<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Point\Contracts\WalletRepository;
use App\Domain\Point\Enums\PointKind;
use App\Domain\Point\Enums\TransactionType;
use App\Domain\Point\Support\PointTransaction;
use App\Models\PointProduct;
use App\Models\PointPurchase;
use App\Models\PointWallet;
use App\Models\User;
use App\Support\Adapters\Stripe\StripeSignatureVerifier;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stripe Webhook。
 *
 * ここが破られる＝架空の返金を作られる、通らない＝返金の取りこぼし。
 * どちらも直接お金に効くので、署名・冪等・回収額の3点を厚めに固める。
 */
final class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => self::SECRET]);
    }

    // --- 署名検証 ---

    public function test_rejects_request_without_signature(): void
    {
        $this->postJson(route('webhooks.stripe'), $this->refundEvent('pi_x', 0))
            ->assertStatus(400);
    }

    public function test_rejects_forged_signature(): void
    {
        $payload = json_encode($this->refundEvent('pi_x', 0));

        $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1='.str_repeat('a', 64),
        ], $payload)->assertStatus(400);
    }

    public function test_rejects_signature_of_a_different_body(): void
    {
        // 正しい署名を別のボディに付け替える改ざん
        $signedFor = json_encode($this->refundEvent('pi_victim', 0));
        $sent = json_encode($this->refundEvent('pi_attacker', 999999));

        $t = time();
        $header = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$signedFor, self::SECRET);

        $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $sent)->assertStatus(400);
    }

    public function test_rejects_replayed_old_signature(): void
    {
        $old = time() - (StripeSignatureVerifier::TOLERANCE_SECONDS + 60);

        $this->send($this->refundEvent('pi_x', 0), timestamp: $old)->assertStatus(400);
    }

    public function test_rejects_everything_when_webhook_secret_is_not_configured(): void
    {
        // 設定漏れが「素通し」になっては困る
        config(['services.stripe.webhook_secret' => null]);

        $this->send($this->refundEvent('pi_x', 0))->assertStatus(400);
    }

    // --- 返金によるポイント回収 ---

    public function test_full_refund_claws_back_all_points(): void
    {
        [$wallet, $purchase] = $this->purchase(points: 3000, yen: 3600, ref: 'pi_full');

        $this->send($this->refundEvent('pi_full', 3600))->assertOk();

        $this->assertSame(0, $this->balance($wallet->id));
        $this->assertNotNull($purchase->fresh()->refunded_at);
        $this->assertSame(3000, $purchase->fresh()->refunded_points);
    }

    public function test_partial_refund_claws_back_proportionally(): void
    {
        [$wallet, $purchase] = $this->purchase(points: 3000, yen: 3600, ref: 'pi_part');

        // 半額返金 → 半分のポイントを回収
        $this->send($this->refundEvent('pi_part', 1800))->assertOk();

        $this->assertSame(1500, $this->balance($wallet->id));
        $this->assertSame(1500, $purchase->fresh()->refunded_points);
    }

    public function test_partial_refund_rounds_up_so_we_never_under_collect(): void
    {
        [$wallet] = $this->purchase(points: 1000, yen: 1200, ref: 'pi_round');

        // 1円返金 → 0.83ポイント相当。切り上げて1ポイント回収する
        $this->send($this->refundEvent('pi_round', 1))->assertOk();

        $this->assertSame(999, $this->balance($wallet->id));
    }

    public function test_refund_is_idempotent_across_retries(): void
    {
        [$wallet] = $this->purchase(points: 3000, yen: 3600, ref: 'pi_retry');

        $event = $this->refundEvent('pi_retry', 3600);

        $this->send($event)->assertOk();
        // Stripe は同じイベントを何度も送ってくる
        $this->send($event)->assertOk();
        $this->send($event)->assertOk();

        $this->assertSame(0, $this->balance($wallet->id));
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_refund_collects_even_when_points_were_already_spent(): void
    {
        // 使い切ってから返金されるケース。黙って取りこぼすより負債として見せる
        [$wallet] = $this->purchase(points: 3000, yen: 3600, ref: 'pi_spent');
        $this->spend($wallet->id, 3000);

        $this->send($this->refundEvent('pi_spent', 3600))->assertOk();

        $this->assertSame(-3000, $this->balance($wallet->id));
    }

    public function test_unknown_charge_ref_is_accepted_without_touching_the_ledger(): void
    {
        // 我々の購入ではない決済（別プロダクト等）。再送を止めるため 200 を返す
        $this->send($this->refundEvent('pi_unknown', 1000))->assertOk();

        $this->assertDatabaseCount('point_transactions', 0);
    }

    // --- チャージバック ---

    public function test_dispute_claws_back_the_full_amount(): void
    {
        [$wallet, $purchase] = $this->purchase(points: 3000, yen: 3600, ref: 'pi_dispute');

        $this->send([
            'id' => 'evt_dispute',
            'type' => 'charge.dispute.created',
            'data' => ['object' => [
                'id' => 'dp_1',
                'payment_intent' => 'pi_dispute',
                'charge' => 'ch_1',
                'amount' => 3600,
                'reason' => 'fraudulent',
            ]],
        ])->assertOk();

        $this->assertSame(0, $this->balance($wallet->id));
        $this->assertNotNull($purchase->fresh()->refunded_at);
    }

    // --- 入金通知 ---

    public function test_payment_succeeded_does_not_double_grant_points(): void
    {
        [$wallet] = $this->purchase(points: 3000, yen: 3600, ref: 'pi_ok');

        $this->send([
            'id' => 'evt_paid',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_ok', 'amount_received' => 3600]],
        ])->assertOk();

        // 付与は購入処理側で済んでいる。Webhook で二重に増えない
        $this->assertSame(3000, $this->balance($wallet->id));
    }

    public function test_unknown_event_types_are_accepted_and_ignored(): void
    {
        $this->send([
            'id' => 'evt_other',
            'type' => 'customer.created',
            'data' => ['object' => ['id' => 'cus_1']],
        ])->assertOk();

        $this->assertDatabaseCount('point_transactions', 0);
    }

    // --- CSRF ---

    public function test_only_the_webhook_route_is_exempt_from_csrf(): void
    {
        // テスト中は CSRF ミドルウェア自体が素通しになるため HTTP では確認できない。
        // 除外が消えれば本番だけ 419 で全滅し、増えれば「認証もCSRFも無い口」が増える。
        // どちらにも気づけるよう、設定そのものを見る
        $this->get(route('health')); // ミドルウェア設定はリクエスト時に組み立てられる

        $this->assertSame(['webhooks/stripe'], $this->csrfExemptPaths());
    }

    /** @return list<string> */
    private function csrfExemptPaths(): array
    {
        $property = new \ReflectionProperty(ValidateCsrfToken::class, 'neverVerify');

        return array_values((array) $property->getValue());
    }

    // --- ヘルパ ---

    /** @param array<string, mixed> $event */
    private function send(array $event, ?int $timestamp = null): TestResponse
    {
        $payload = json_encode($event, JSON_UNESCAPED_UNICODE);
        $t = $timestamp ?? time();
        $header = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, self::SECRET);

        return $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload);
    }

    /** @return array<string, mixed> */
    private function refundEvent(string $paymentIntent, int $amountRefunded): array
    {
        return [
            'id' => 'evt_refund_'.$paymentIntent,
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_'.$paymentIntent,
                'payment_intent' => $paymentIntent,
                'amount_refunded' => $amountRefunded,
            ]],
        ];
    }

    /** @return array{0: PointWallet, 1: PointPurchase} */
    private function purchase(int $points, int $yen, string $ref): array
    {
        $user = User::create([
            'email' => $ref.'@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'ゲスト',
        ]);
        $wallet = PointWallet::create(['user_id' => $user->id]);
        $product = PointProduct::create(['paid_points' => $points, 'price_yen' => $yen, 'active' => true]);

        app(WalletRepository::class)->append(
            $wallet->id,
            PointTransaction::purchase($points, now()->addDays(180)->toDateString()),
            'seed-'.$ref,
        );

        $purchase = PointPurchase::create([
            'wallet_id' => $wallet->id,
            'product_id' => $product->id,
            'paid_points' => $points,
            'price_yen' => $yen,
            'charge_ref' => $ref,
        ]);

        return [$wallet, $purchase];
    }

    private function spend(int $walletId, int $points): void
    {
        app(WalletRepository::class)->append(
            $walletId,
            new PointTransaction(
                TransactionType::Capture,
                PointKind::Paid,
                $points,
            ),
            'spend-'.$walletId.'-'.$points,
        );
    }

    private function balance(int $walletId): int
    {
        return app(WalletRepository::class)->load($walletId)->balance()->settled();
    }
}
