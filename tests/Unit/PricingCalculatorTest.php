<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Pricing\DTO\CallLineItem;
use App\Domain\Pricing\Enums\CastClass;
use App\Domain\Pricing\PricingCalculator;
use App\Domain\Pricing\Support\AreaClassPrice;
use PHPUnit\Framework\TestCase;

final class PricingCalculatorTest extends TestCase
{
    private PricingCalculator $calc;

    /** @var array<string, AreaClassPrice> */
    private array $table;

    protected function setUp(): void
    {
        $this->calc = new PricingCalculator;
        $this->table = PricingCalculator::okayamaDefaultTable();
    }

    public function test_premium_30min_single(): void
    {
        $quote = $this->calc->quote(
            $this->table,
            [new CallLineItem(CastClass::Premium, headcount: 1)],
            durationMin: 30,
        );

        // 岡山プレミアム3,000P/30分, 1P=¥1.2, テイクレート40%
        $this->assertSame(3000, $quote->guestHoldPoints);
        $this->assertSame(3600, $quote->guestYen());
        $this->assertSame(2160, $quote->castPayoutPoints);
        $this->assertSame(1440, $quote->takePoints);
    }

    public function test_premium_one_hour(): void
    {
        $quote = $this->calc->quote(
            $this->table,
            [new CallLineItem(CastClass::Premium, headcount: 1)],
            durationMin: 60,
        );

        $this->assertSame(6000, $quote->guestHoldPoints);
        $this->assertSame(7200, $quote->guestYen());
        $this->assertSame(4320, $quote->castPayoutPoints);
    }

    public function test_mix_royalvip_and_vip_one_hour(): void
    {
        $quote = $this->calc->quote(
            $this->table,
            [
                new CallLineItem(CastClass::RoyalVip, headcount: 1),
                new CallLineItem(CastClass::Vip, headcount: 1),
            ],
            durationMin: 60,
        );

        // RoyalVIP 20,000P + VIP 11,000P
        $this->assertSame(31000, $quote->guestHoldPoints);
        $this->assertSame(37200, $quote->guestYen());
        // cast: 14,400 + 7,920
        $this->assertSame(22320, $quote->castPayoutPoints);
        $this->assertSame(14880, $quote->takePoints);
    }

    public function test_night_surcharge_adds_20_percent(): void
    {
        $quote = $this->calc->quote(
            $this->table,
            [new CallLineItem(CastClass::Premium, headcount: 1)],
            durationMin: 30,
            isNight: true,
        );

        // 3,000P × (1 + 0.20) = 3,600P
        $this->assertSame(3600, $quote->guestHoldPoints);
    }

    public function test_headcount_multiplies(): void
    {
        $quote = $this->calc->quote(
            $this->table,
            [new CallLineItem(CastClass::Premium, headcount: 3)],
            durationMin: 30,
        );

        $this->assertSame(9000, $quote->guestHoldPoints);
        $this->assertSame(6480, $quote->castPayoutPoints);
    }

    public function test_duration_must_be_multiple_of_30(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->calc->quote(
            $this->table,
            [new CallLineItem(CastClass::Premium, headcount: 1)],
            durationMin: 45,
        );
    }
}
