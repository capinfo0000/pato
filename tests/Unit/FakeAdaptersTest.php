<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Adapters\Fake\FakeEkycProvider;
use App\Support\Adapters\Fake\FakePaymentGateway;
use App\Support\Adapters\Fake\FakePushSender;
use App\Support\DTO\EkycResult;
use PHPUnit\Framework\TestCase;

final class FakeAdaptersTest extends TestCase
{
    public function test_payment_is_idempotent(): void
    {
        $gw = new FakePaymentGateway();
        $ref1 = $gw->charge('tok_visa', 12000, 'key-1');
        $ref2 = $gw->charge('tok_visa', 12000, 'key-1'); // 同じ冪等キー

        $this->assertSame($ref1, $ref2);
        $this->assertSame(1, $gw->chargeCount());
    }

    public function test_payment_failure_throws(): void
    {
        $gw = new FakePaymentGateway();
        $gw->shouldFail = true;

        $this->expectException(\RuntimeException::class);
        $gw->charge('tok_visa', 12000, 'key-2');
    }

    public function test_ekyc_stub_returns_configured_result(): void
    {
        $ekyc = new FakeEkycProvider();
        $ekyc->stub('sess-1', new EkycResult(verified: true, isAdult: true, providerRef: 'p-1'));

        $result = $ekyc->verify('sess-1');
        $this->assertTrue($result->verified);
        $this->assertTrue($result->isAdult);

        // 未仕込みは未確認扱い
        $this->assertFalse($ekyc->verify('unknown')->verified);
    }

    public function test_push_records_sent_messages(): void
    {
        $push = new FakePushSender();
        $push->send(100, '成立しました', 'キャストが向かっています');

        $this->assertSame(1, $push->countFor(100));
        $this->assertSame(0, $push->countFor(999));
    }
}
