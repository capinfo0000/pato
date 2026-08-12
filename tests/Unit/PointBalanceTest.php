<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Point\Enums\PointKind;
use App\Domain\Point\Support\PointBalance;
use App\Domain\Point\Support\PointTransaction;
use PHPUnit\Framework\TestCase;

final class PointBalanceTest extends TestCase
{
    public function test_purchase_increases_available(): void
    {
        $balance = new PointBalance([
            PointTransaction::purchase(10000),
        ]);

        $this->assertSame(10000, $balance->settled());
        $this->assertSame(0, $balance->outstandingHold());
        $this->assertSame(10000, $balance->available());
    }

    public function test_hold_reduces_available_but_not_settled(): void
    {
        $balance = new PointBalance([
            PointTransaction::purchase(10000),
            PointTransaction::hold(3600, callId: 1),
        ]);

        $this->assertSame(10000, $balance->settled());
        $this->assertSame(3600, $balance->outstandingHold());
        $this->assertSame(6400, $balance->available());
    }

    public function test_capture_finalizes_hold_and_spends(): void
    {
        $balance = new PointBalance([
            PointTransaction::purchase(10000),
            PointTransaction::hold(3600, callId: 1),
            PointTransaction::capture(3600, callId: 1),
        ]);

        // 実際に消費されたので settled は減り、ホールドは解消
        $this->assertSame(6400, $balance->settled());
        $this->assertSame(0, $balance->outstandingHold());
        $this->assertSame(6400, $balance->available());
    }

    public function test_release_returns_hold_without_spending(): void
    {
        $balance = new PointBalance([
            PointTransaction::purchase(10000),
            PointTransaction::hold(3600, callId: 1),
            PointTransaction::release(3600, callId: 1),
        ]);

        $this->assertSame(10000, $balance->settled());
        $this->assertSame(0, $balance->outstandingHold());
        $this->assertSame(10000, $balance->available());
    }

    public function test_tip_and_expire_reduce_balance(): void
    {
        $balance = new PointBalance([
            PointTransaction::purchase(10000),
            PointTransaction::tip(5000, callId: 1),
            PointTransaction::expire(1000, PointKind::Free),
        ]);

        $this->assertSame(4000, $balance->settled());
        $this->assertSame(4000, $balance->available());
    }

    public function test_can_hold_respects_available(): void
    {
        $balance = new PointBalance([
            PointTransaction::purchase(5000),
            PointTransaction::hold(2000, callId: 1),
        ]);

        $this->assertTrue($balance->canHold(3000));
        $this->assertFalse($balance->canHold(3001));
    }
}
