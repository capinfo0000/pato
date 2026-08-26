<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Call\CallBillingService;
use App\Domain\Call\Call;
use App\Domain\Call\Enums\CallStatus;
use App\Domain\Point\Support\PointTransaction;
use App\Domain\Point\Support\Wallet;
use App\Domain\Pricing\DTO\CallLineItem;
use App\Domain\Pricing\Enums\CastClass;
use App\Domain\Pricing\PricingCalculator;
use PHPUnit\Framework\TestCase;

final class CallBillingServiceTest extends TestCase
{
    private CallBillingService $service;

    private PricingCalculator $calc;

    protected function setUp(): void
    {
        $this->service = new CallBillingService;
        $this->calc = new PricingCalculator;
    }

    public function test_full_flow_create_match_complete_pays_out(): void
    {
        // ゲスト: 10,000P 購入済み
        $guest = new Wallet([PointTransaction::purchase(10000)]);
        // 岡山プレミアム 30分 1名 → ゲスト3,000P / キャスト2,160P
        $quote = $this->calc->quote(
            PricingCalculator::okayamaDefaultTable(),
            [new CallLineItem(CastClass::Premium, 1)],
            durationMin: 30,
        );

        $call = new Call(id: 1, guestUserId: 100, holdPoints: $quote->guestHoldPoints);

        // 作成→与信
        $this->service->createAndHold($call, $guest, $quote);
        $this->assertSame(CallStatus::Open, $call->status());
        $this->assertSame(7000, $guest->balance()->available()); // 10000 - 3000 hold
        $this->assertSame(10000, $guest->balance()->settled());

        // 成立→開始
        $call->match([501]);
        $call->start();

        // 完了→確定消費＋報酬計上
        $castWallet = new Wallet;
        $payouts = $this->service->complete($call, $guest, $quote, [501 => $castWallet]);

        $this->assertSame(CallStatus::Completed, $call->status());
        $this->assertSame(7000, $guest->balance()->settled());   // 3000 消費
        $this->assertSame(0, $guest->balance()->outstandingHold());
        $this->assertSame([501 => 2160], $payouts);
        $this->assertSame(2160, $castWallet->balance()->settled());
    }

    public function test_insufficient_points_blocks_creation(): void
    {
        $guest = new Wallet([PointTransaction::purchase(1000)]);
        $quote = $this->calc->quote(
            PricingCalculator::okayamaDefaultTable(),
            [new CallLineItem(CastClass::Premium, 1)],
            durationMin: 30,
        );
        $call = new Call(id: 2, guestUserId: 100, holdPoints: $quote->guestHoldPoints);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('insufficient_points');
        $this->service->createAndHold($call, $guest, $quote);
    }

    public function test_cancel_releases_hold(): void
    {
        $guest = new Wallet([PointTransaction::purchase(20000)]);
        $quote = $this->calc->quote(
            PricingCalculator::okayamaDefaultTable(),
            [new CallLineItem(CastClass::Vip, 1)],
            durationMin: 60,
        );
        // VIP 5,500P/30分 × 2コマ = 11,000P
        $this->assertSame(11000, $quote->guestHoldPoints);
        $call = new Call(id: 3, guestUserId: 100, holdPoints: $quote->guestHoldPoints);

        $this->service->createAndHold($call, $guest, $quote);
        $this->assertSame(20000 - 11000, $guest->balance()->available());

        $this->service->release($call, $guest, $quote);
        $this->assertSame(CallStatus::Canceled, $call->status());
        $this->assertSame(20000, $guest->balance()->available()); // 全額戻る
    }

    public function test_tip_distributes_equally_and_moves_points(): void
    {
        $guest = new Wallet([PointTransaction::purchase(20000)]);
        $quote = $this->calc->quote(
            PricingCalculator::okayamaDefaultTable(),
            [new CallLineItem(CastClass::Premium, 2)],
            durationMin: 30,
        );
        $call = new Call(id: 4, guestUserId: 100, holdPoints: $quote->guestHoldPoints);
        $this->service->createAndHold($call, $guest, $quote);
        $call->match([501, 502]);
        $call->start();

        $castA = new Wallet;
        $castB = new Wallet;
        $dist = $this->service->applyTip($call, $guest, [501 => $castA, 502 => $castB], 5000);

        $this->assertSame([501 => 2500, 502 => 2500], $dist);
        $this->assertSame(2500, $castA->balance()->settled());
        $this->assertSame(2500, $castB->balance()->settled());
    }
}
