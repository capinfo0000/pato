<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Adapters\Fake\FakeEkycProvider;
use App\Support\Adapters\Fake\FakePaymentGateway;
use App\Support\Adapters\Fake\FakePushSender;
use App\Support\Contracts\EkycProvider;
use App\Support\Contracts\PaymentGateway;
use App\Support\Contracts\PushSender;
use Tests\TestCase;

/**
 * テストが本物の外部サービスを叩かないことを保証する。
 *
 * `.env` に実キーが入ったまま `phpunit.xml` の上書きが外れると、テストが
 * **本物の Stripe に課金しに行く**（レート制限のテストは購入を6回叩く）。
 * 気づきにくく、気づいたときには請求が立っている類の事故なので、明示的に固定する。
 */
final class NoRealCredentialsInTestsTest extends TestCase
{
    public function test_payment_gateway_is_always_a_fake_in_tests(): void
    {
        $this->assertInstanceOf(
            FakePaymentGateway::class,
            app(PaymentGateway::class),
            'テストが実決済アダプタを使っている。phpunit.xml の STRIPE_SECRET 上書きを確認すること',
        );
    }

    public function test_other_external_adapters_are_fakes_too(): void
    {
        $this->assertInstanceOf(FakeEkycProvider::class, app(EkycProvider::class));
        $this->assertInstanceOf(FakePushSender::class, app(PushSender::class));
    }

    public function test_no_real_looking_credentials_are_visible_to_tests(): void
    {
        // 本物のキーは接頭辞で見分けられる。値そのものはアサーションに出さない
        foreach ([
            'services.stripe.secret' => 'sk_',
            'services.stripe.publishable_key' => 'pk_',
            'services.stripe.webhook_secret' => 'whsec_',
        ] as $key => $prefix) {
            $this->assertStringStartsNotWith(
                $prefix,
                (string) config($key),
                "テスト環境に実キーらしき値が見えている: {$key}",
            );
        }
    }
}
