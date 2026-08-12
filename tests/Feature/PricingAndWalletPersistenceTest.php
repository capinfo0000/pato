<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Point\Support\PointTransaction as PointTx;
use App\Domain\Pricing\DTO\CallLineItem;
use App\Domain\Pricing\Enums\CastClass;
use App\Domain\Pricing\PricingCalculator;
use App\Infrastructure\Persistence\EloquentPriceTableRepository;
use App\Infrastructure\Persistence\EloquentWalletRepository;
use App\Models\Area;
use App\Models\PointWallet;
use App\Models\User;
use Database\Seeders\OkayamaMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PricingAndWalletPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_table_from_db_feeds_calculator(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $areaId = Area::where('serviceable', true)->value('id');

        $table = (new EloquentPriceTableRepository)->forArea($areaId);
        $quote = (new PricingCalculator)->quote(
            $table,
            [new CallLineItem(CastClass::Premium, 1)],
            durationMin: 30,
        );

        // 岡山プレミアム 3,000P/30分, テイクレート40%
        $this->assertSame(3000, $quote->guestHoldPoints);
        $this->assertSame(2160, $quote->castPayoutPoints);
        $this->assertSame(1440, $quote->takePoints);
    }

    public function test_wallet_ledger_persists_and_reloads(): void
    {
        $user = User::create([
            'email' => 'guest@example.test',
            'password' => 'secret-password',
            'role' => 'guest',
            'nickname' => 'テストゲスト',
        ]);
        $wallet = PointWallet::create(['user_id' => $user->id]);

        $repo = new EloquentWalletRepository;
        $repo->append($wallet->id, PointTx::purchase(10000), 'purchase-1');
        $repo->append($wallet->id, PointTx::hold(3600, callId: 1), 'hold-call-1');

        $balance = $repo->load($wallet->id)->balance();

        $this->assertSame(10000, $balance->settled());
        $this->assertSame(3600, $balance->outstandingHold());
        $this->assertSame(6400, $balance->available());
        $this->assertDatabaseCount('point_transactions', 2);
    }

    public function test_only_okayama_central_is_serviceable(): void
    {
        $this->seed(OkayamaMasterSeeder::class);

        $this->assertSame(1, Area::where('serviceable', true)->count());
        $this->assertSame('岡山市中心部', Area::where('serviceable', true)->value('name'));
    }
}
