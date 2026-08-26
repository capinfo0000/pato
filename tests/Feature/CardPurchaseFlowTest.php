<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Point\PurchasePointService;
use App\Domain\Point\Contracts\WalletRepository;
use App\Models\PointProduct;
use App\Models\PointWallet;
use App\Models\User;
use App\Support\Adapters\Fake\FakePaymentGateway;
use App\Support\Contracts\PaymentGateway;
use Database\Seeders\OkayamaMasterSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * カード購入（Stripe Elements + 3Dセキュア）の画面〜サーバ側フロー。
 *
 * 3Dセキュアは「一旦保留 → ブラウザで認証 → サーバで確定」の2段階になる。
 * 確定はクライアントの申告を信用しないことが要で、そこを重点的に固定する。
 */
final class CardPurchaseFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OkayamaMasterSeeder::class);

        $this->user = User::create([
            'email' => 'card@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'かいもの',
        ]);
        PointWallet::create(['user_id' => $this->user->id]);

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    // --- 画面 ---

    public function test_card_form_is_shown_when_a_publishable_key_is_configured(): void
    {
        config(['services.stripe.publishable_key' => 'pk_test_dummy']);

        $response = $this->actingAs($this->user)->get(route('points.index'));

        $response->assertOk();
        $response->assertSee('card-element', escape: false);
        $response->assertSee('https://js.stripe.com/v3/', escape: false);
        // 公開可能キーはブラウザに出てよい
        $response->assertSee('pk_test_dummy', escape: false);
    }

    public function test_the_secret_key_never_reaches_the_browser(): void
    {
        config([
            'services.stripe.publishable_key' => 'pk_test_dummy',
            'services.stripe.secret' => 'sk_test_must_not_leak',
            'services.stripe.webhook_secret' => 'whsec_must_not_leak',
        ]);

        $response = $this->actingAs($this->user)->get(route('points.index'));

        $response->assertDontSee('sk_test_must_not_leak', escape: false);
        $response->assertDontSee('whsec_must_not_leak', escape: false);
    }

    public function test_demo_form_is_shown_when_no_key_is_configured(): void
    {
        config(['services.stripe.publishable_key' => null]);

        $this->actingAs($this->user)->get(route('points.index'))
            ->assertOk()
            ->assertDontSee('card-element', escape: false);
    }

    // --- 通常購入 ---

    public function test_a_successful_charge_grants_points_immediately(): void
    {
        $product = PointProduct::where('paid_points', 10000)->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('points.purchase'), [
                'product_id' => $product->id,
                'payment_method_token' => 'pm_card_visa',
            ])
            ->assertRedirect(route('points.index'));

        $this->assertSame(10000, $this->balance());
        $this->assertDatabaseHas('point_purchases', ['paid_points' => 10000]);
    }

    public function test_the_same_charge_can_never_grant_points_twice(): void
    {
        // 台帳の冪等キーを決済参照IDから作っているため、同じ決済は二度計上できない
        $product = PointProduct::where('paid_points', 10000)->firstOrFail();
        $service = app(PurchasePointService::class);

        $result = $service->purchase($this->user->id, $product, 'pm_card_visa');

        $this->expectException(UniqueConstraintViolationException::class);
        $service->finalize($this->user->id, $product, $result['charge_ref']);
    }

    // --- 3Dセキュア ---

    public function test_3d_secure_holds_the_points_until_the_browser_confirms(): void
    {
        $this->gateway->requiresAction = true;
        $product = PointProduct::where('paid_points', 10000)->firstOrFail();

        $response = $this->actingAs($this->user)->post(route('points.purchase'), [
            'product_id' => $product->id,
            'payment_method_token' => 'pm_card_3ds',
        ]);

        // 認証前にポイントを付けない
        $this->assertSame(0, $this->balance());
        $this->assertDatabaseCount('point_purchases', 0);

        $response->assertSessionHas('stripe_action');
        $this->assertNotNull(session('stripe_action')['client_secret']);
    }

    public function test_confirming_after_3d_secure_grants_the_points(): void
    {
        $this->gateway->requiresAction = true;
        $product = PointProduct::where('paid_points', 10000)->firstOrFail();

        $this->actingAs($this->user)->post(route('points.purchase'), [
            'product_id' => $product->id,
            'payment_method_token' => 'pm_card_3ds',
        ]);

        $this->actingAs($this->user)->post(route('points.confirm'))
            ->assertRedirect(route('points.index'));

        $this->assertSame(10000, $this->balance());
    }

    public function test_confirming_without_a_pending_charge_does_nothing(): void
    {
        // セッションに何も無い状態で確定を叩いても、ポイントは増えない
        $this->actingAs($this->user)->post(route('points.confirm'))
            ->assertRedirect(route('points.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, $this->balance());
    }

    public function test_confirming_twice_does_not_double_grant(): void
    {
        $this->gateway->requiresAction = true;
        $product = PointProduct::where('paid_points', 10000)->firstOrFail();

        $this->actingAs($this->user)->post(route('points.purchase'), [
            'product_id' => $product->id,
            'payment_method_token' => 'pm_card_3ds',
        ]);

        $this->actingAs($this->user)->post(route('points.confirm'));
        // 2回目はセッションが消費済みなので何も起きない
        $this->actingAs($this->user)->post(route('points.confirm'));

        $this->assertSame(10000, $this->balance());
    }

    public function test_points_are_not_granted_when_the_payment_is_still_unconfirmed(): void
    {
        // 「認証した」とブラウザが言っても、PSP 側が未確定ならポイントは出さない
        $this->gateway->requiresAction = true;
        $product = PointProduct::where('paid_points', 10000)->firstOrFail();

        $this->actingAs($this->user)->post(route('points.purchase'), [
            'product_id' => $product->id,
            'payment_method_token' => 'pm_card_3ds',
        ]);
        $this->gateway->stubLookup(session('points.pending_charge')['charge_ref'], 'requires_payment_method');

        $this->actingAs($this->user)->post(route('points.confirm'))
            ->assertSessionHas('error');

        $this->assertSame(0, $this->balance());
    }

    public function test_points_are_not_granted_when_the_paid_amount_does_not_match(): void
    {
        // 少額の決済で高額商品のポイントを受け取らせない
        $this->gateway->requiresAction = true;
        $product = PointProduct::where('paid_points', 10000)->firstOrFail();

        $this->actingAs($this->user)->post(route('points.purchase'), [
            'product_id' => $product->id,
            'payment_method_token' => 'pm_card_3ds',
        ]);
        $ref = session('points.pending_charge')['charge_ref'];
        // 決済は成立しているが、払われたのは100円だけ
        $this->gateway->stubLookup($ref, 'succeeded', amountYen: 100);

        $service = app(PurchasePointService::class);
        try {
            $service->finalize($this->user->id, $product, $ref);
            $this->fail('金額不一致が通ってしまった');
        } catch (\RuntimeException $e) {
            $this->assertSame('payment_amount_mismatch', $e->getMessage());
        }

        $this->assertSame(0, $this->balance());
    }

    private function balance(): int
    {
        $wallet = PointWallet::where('user_id', $this->user->id)->firstOrFail();

        return app(WalletRepository::class)->load($wallet->id)->balance()->settled();
    }
}
