<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Point\PurchasePointService;
use App\Domain\Point\Contracts\WalletRepository;
use App\Jobs\SendPushJob;
use App\Models\PointProduct;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Adapters\Ekyc\HttpEkycProvider;
use App\Support\Adapters\Fake\FakeEkycProvider;
use App\Support\Adapters\Fake\FakePaymentGateway;
use App\Support\Adapters\Fake\FakePushSender;
use App\Support\Adapters\Push\WebPushFactory;
use App\Support\Adapters\Push\WebPushSender;
use App\Support\Adapters\Stripe\StripePaymentGateway;
use App\Support\Contracts\EkycProvider;
use App\Support\Contracts\PaymentGateway;
use App\Support\Contracts\PushSender;
use Database\Seeders\OkayamaMasterSeeder;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Tests\TestCase;

final class RealAdaptersTest extends TestCase
{
    use RefreshDatabase;

    /** Web Push の鍵は URL-safe base64（パディング無し）で扱う。 */
    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    // --- DI: 認証情報の有無で Fake / 実アダプタが切り替わる ---

    public function test_adapters_fall_back_to_fakes_without_credentials(): void
    {
        config([
            'services.stripe.secret' => null,
            'services.ekyc.base_url' => null,
            'services.ekyc.api_key' => null,
            'services.webpush.public_key' => null,
        ]);
        $this->refreshApplication();

        $this->assertInstanceOf(FakePaymentGateway::class, app(PaymentGateway::class));
        $this->assertInstanceOf(FakeEkycProvider::class, app(EkycProvider::class));
        $this->assertInstanceOf(FakePushSender::class, app(PushSender::class));
    }

    public function test_adapters_switch_to_real_implementations_with_credentials(): void
    {
        $this->app->forgetInstance(PaymentGateway::class);
        $this->app->forgetInstance(EkycProvider::class);
        $this->app->forgetInstance(PushSender::class);

        config([
            'services.stripe.secret' => 'sk_test_dummy',
            'services.ekyc.base_url' => 'https://ekyc.example.test',
            'services.ekyc.api_key' => 'key_dummy',
            'services.webpush.public_key' => 'vapid_public_dummy',
        ]);

        $this->assertInstanceOf(StripePaymentGateway::class, app(PaymentGateway::class));
        $this->assertInstanceOf(HttpEkycProvider::class, app(EkycProvider::class));
        $this->assertInstanceOf(WebPushSender::class, app(PushSender::class));
    }

    // --- Stripe ---

    public function test_stripe_charges_with_an_idempotency_key(): void
    {
        Http::fake([
            'api.stripe.com/*' => Http::response(['id' => 'pi_123', 'status' => 'succeeded'], 200),
        ]);

        $result = (new StripePaymentGateway('sk_test_dummy'))->charge('pm_card_visa', 12000, 'key-1');

        $this->assertTrue($result->succeeded());
        $this->assertSame('pi_123', $result->reference);
        Http::assertSent(function ($request) {
            return $request->hasHeader('Idempotency-Key', 'key-1')
                && $request['amount'] === 12000       // JPY はゼロ小数通貨。円をそのまま送る
                && $request['currency'] === 'jpy'
                && $request['payment_method'] === 'pm_card_visa';
        });
    }

    public function test_stripe_failure_raises_and_leaves_the_ledger_untouched(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        Http::fake([
            'api.stripe.com/*' => Http::response(['error' => ['code' => 'card_declined']], 402),
        ]);

        $user = User::create([
            'email' => 'p@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'かいもの',
        ]);
        $product = PointProduct::where('paid_points', 10000)->firstOrFail();

        $service = new PurchasePointService(
            new StripePaymentGateway('sk_test_dummy'),
            app(WalletRepository::class),
        );

        try {
            $service->purchase($user->id, $product, 'pm_card_visa');
            $this->fail('決済失敗が例外にならなかった');
        } catch (\RuntimeException $e) {
            $this->assertSame('payment_failed', $e->getMessage());
        }

        // 課金できていないのにポイントが増えていない
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_stripe_rejects_non_succeeded_status(): void
    {
        // 3Dセキュア以外の未完了（カード再入力待ちなど）は失敗として扱う
        Http::fake([
            'api.stripe.com/*' => Http::response(['id' => 'pi_1', 'status' => 'requires_payment_method'], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        (new StripePaymentGateway('sk_test_dummy'))->charge('pm_card_visa', 12000, 'key-2');
    }

    public function test_stripe_returns_requires_action_for_3d_secure_instead_of_failing(): void
    {
        // 日本のカードでは3Dセキュアが実質必須。ここで例外にすると正常な購入が全部落ちる
        Http::fake([
            'api.stripe.com/*' => Http::response([
                'id' => 'pi_3ds', 'status' => 'requires_action',
                'amount' => 12000, 'currency' => 'jpy', 'client_secret' => 'pi_3ds_secret_x',
            ], 200),
        ]);

        $result = (new StripePaymentGateway('sk_test_dummy'))->charge('pm_card_visa', 12000, 'key-3');

        $this->assertTrue($result->requiresAction());
        $this->assertSame('pi_3ds_secret_x', $result->clientSecret);
    }

    public function test_stripe_confirm_reads_back_the_authoritative_amount_and_status(): void
    {
        // 確定時はクライアントの申告ではなく、Stripe が返す金額と状態を使う
        Http::fake([
            'api.stripe.com/v1/payment_intents/pi_3ds' => Http::response([
                'id' => 'pi_3ds', 'status' => 'succeeded', 'amount' => 12000, 'currency' => 'jpy',
            ], 200),
        ]);

        $result = (new StripePaymentGateway('sk_test_dummy'))->confirm('pi_3ds');

        $this->assertTrue($result->succeeded());
        $this->assertSame(12000, $result->amountYen);
    }

    // --- eKYC ---

    public function test_ekyc_maps_a_completed_adult_verification(): void
    {
        Http::fake([
            'ekyc.example.test/*' => Http::response([
                'status' => 'completed',
                'date_of_birth' => now()->subYears(30)->toDateString(),
            ], 200),
        ]);

        $result = (new HttpEkycProvider('https://ekyc.example.test', 'key'))->verify('sess-1');

        $this->assertTrue($result->verified);
        $this->assertTrue($result->isAdult);
        $this->assertSame('sess-1', $result->providerRef);
    }

    public function test_ekyc_marks_a_minor_as_not_adult(): void
    {
        Http::fake([
            'ekyc.example.test/*' => Http::response([
                'status' => 'completed',
                'date_of_birth' => now()->subYears(17)->toDateString(),
            ], 200),
        ]);

        $result = (new HttpEkycProvider('https://ekyc.example.test', 'key'))->verify('sess-2');

        $this->assertTrue($result->verified);
        $this->assertFalse($result->isAdult);
    }

    public function test_ekyc_failure_is_treated_as_unverified(): void
    {
        Http::fake(['ekyc.example.test/*' => Http::response([], 500)]);

        $result = (new HttpEkycProvider('https://ekyc.example.test', 'key'))->verify('sess-3');

        $this->assertFalse($result->verified);
        $this->assertFalse($result->isAdult);
    }

    // --- Web Push ---

    public function test_push_subscription_is_stored_and_removed(): void
    {
        $user = User::create([
            'email' => 'u@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'ゲスト',
        ]);
        $payload = [
            'endpoint' => 'https://push.example.test/abc',
            'keys' => ['p256dh' => 'pubkey', 'auth' => 'authtoken'],
        ];

        $this->actingAs($user)->postJson(route('push.subscribe'), $payload)->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);

        // 同じ endpoint の再登録は増えない
        $this->actingAs($user)->postJson(route('push.subscribe'), $payload)->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);

        $this->actingAs($user)->postJson(route('push.unsubscribe'), ['endpoint' => $payload['endpoint']])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_web_push_sender_queues_one_job_per_subscription(): void
    {
        Queue::fake();

        $user = User::create([
            'email' => 'u2@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'ゲスト',
        ]);
        foreach (['https://push.example.test/a', 'https://push.example.test/b'] as $endpoint) {
            PushSubscription::create([
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'endpoint_hash' => PushSubscription::hashFor($endpoint),
                'public_key' => 'pub',
                'auth_token' => 'auth',
            ]);
        }

        (new WebPushSender)->send($user->id, '成立しました', 'キャストが向かっています');

        Queue::assertPushed(SendPushJob::class, 2);
    }

    public function test_expired_subscription_is_deleted_on_send(): void
    {
        // VAPID は実際の鍵形式でないと署名できないため、その場で生成する
        $keys = VAPID::createVapidKeys();
        config([
            'services.webpush.public_key' => $keys['publicKey'],
            'services.webpush.private_key' => $keys['privateKey'],
            'services.webpush.subject' => 'mailto:test@example.com',
        ]);

        $user = User::create([
            'email' => 'u3@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'ゲスト',
        ]);
        // 購読側の公開鍵も P-256 曲線上の実在する点でないと ECDH に失敗する
        $browserKeys = VAPID::createVapidKeys();
        $subscription = PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => 'https://push.example.test/gone',
            'endpoint_hash' => PushSubscription::hashFor('https://push.example.test/gone'),
            'public_key' => $browserKeys['publicKey'],
            'auth_token' => self::base64Url(random_bytes(16)),
        ]);

        // 配信先が 410 Gone を返した状況を作る（web-push は Guzzle を直に使うのでハンドラを差す）
        $this->app->bind(WebPushFactory::class, fn () => new class extends WebPushFactory
        {
            public function make(array $clientOptions = []): WebPush
            {
                $mock = new MockHandler([new GuzzleResponse(410)]);

                return parent::make(['handler' => HandlerStack::create($mock)]);
            }
        });

        // gmp/bcmath が無い環境では web-push が警告を出す（本番では拡張を入れる。
        // release-check で検出する）。Laravel が例外化するのでこの間だけ黙らせる
        set_error_handler(static fn () => true);
        try {
            (new SendPushJob($subscription->id, 'タイトル', '本文'))->handle();
        } finally {
            restore_error_handler();
        }

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_subscription_keys_are_hidden_from_serialization(): void
    {
        $user = User::create([
            'email' => 'u4@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'ゲスト',
        ]);
        $subscription = PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => 'https://push.example.test/x',
            'endpoint_hash' => PushSubscription::hashFor('https://push.example.test/x'),
            'public_key' => 'pub',
            'auth_token' => 'auth',
        ]);

        $this->assertArrayNotHasKey('public_key', $subscription->toArray());
        $this->assertArrayNotHasKey('auth_token', $subscription->toArray());
    }
}
