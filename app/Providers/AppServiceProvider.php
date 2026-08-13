<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Point\Contracts\WalletRepository;
use App\Domain\Pricing\Contracts\PriceTableRepository;
use App\Infrastructure\Persistence\EloquentPriceTableRepository;
use App\Infrastructure\Persistence\EloquentWalletRepository;
use App\Support\Adapters\Fake\FakeEkycProvider;
use App\Support\Adapters\Fake\FakePaymentGateway;
use App\Support\Adapters\Fake\FakePushSender;
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

        // 外部依存 → 実 Adapter 選定までは Fake（決済/eKYC/Push）
        // 本番は Stripe 等の Adapter に差し替える（docs/01 §5）。
        $this->app->singleton(PaymentGateway::class, FakePaymentGateway::class);
        $this->app->singleton(EkycProvider::class, FakeEkycProvider::class);
        $this->app->singleton(PushSender::class, FakePushSender::class);
    }

    public function boot(): void
    {
        //
    }
}
