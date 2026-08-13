<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Point\Contracts\WalletRepository;
use App\Domain\Pricing\Contracts\PriceTableRepository;
use App\Infrastructure\Persistence\EloquentPriceTableRepository;
use App\Infrastructure\Persistence\EloquentWalletRepository;
use App\Support\Adapters\Ekyc\HttpEkycProvider;
use App\Support\Adapters\Fake\FakeEkycProvider;
use App\Support\Adapters\Fake\FakePaymentGateway;
use App\Support\Adapters\Fake\FakePushSender;
use App\Support\Adapters\Push\WebPushSender;
use App\Support\Adapters\Stripe\StripePaymentGateway;
use App\Support\Contracts\EkycProvider;
use App\Support\Contracts\PaymentGateway;
use App\Support\Contracts\PushSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ドメイン契約 → Eloquent 実装
        $this->app->bind(PriceTableRepository::class, EloquentPriceTableRepository::class);
        $this->app->bind(WalletRepository::class, EloquentWalletRepository::class);

        // 外部依存: 認証情報が設定されていれば実アダプタ、無ければ Fake。
        // 本番で Fake のままなら pato:release-check が公開を止める。
        $this->app->singleton(PaymentGateway::class, function () {
            $secret = config('services.stripe.secret');

            return blank($secret)
                ? new FakePaymentGateway
                : new StripePaymentGateway($secret, (string) config('services.stripe.currency', 'jpy'));
        });

        $this->app->singleton(EkycProvider::class, function () {
            $baseUrl = config('services.ekyc.base_url');
            $apiKey = config('services.ekyc.api_key');

            return blank($baseUrl) || blank($apiKey)
                ? new FakeEkycProvider
                : new HttpEkycProvider($baseUrl, $apiKey, minAge: (int) config('pato.min_age', 18));
        });

        $this->app->singleton(PushSender::class, function () {
            return blank(config('services.webpush.public_key'))
                ? new FakePushSender
                : new WebPushSender;
        });
    }

    public function boot(): void
    {
        //
    }
}
