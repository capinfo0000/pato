<?php

declare(strict_types=1);

namespace App\Support\Adapters\Fake;

use App\Support\Contracts\PaymentGateway;

/**
 * テスト/ローカル用の決済 Fake。冪等キーで二重課金を防ぐ挙動を模倣する。
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, string> idempotencyKey => ref */
    private array $charges = [];

    private int $counter = 0;

    public bool $shouldFail = false;

    public function charge(string $paymentMethodToken, int $amountYen, string $idempotencyKey): string
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('payment_failed');
        }
        if ($amountYen <= 0) {
            throw new \InvalidArgumentException('amountYen は正');
        }
        if (isset($this->charges[$idempotencyKey])) {
            return $this->charges[$idempotencyKey];
        }

        $ref = 'fake_ch_'.(++$this->counter);

        return $this->charges[$idempotencyKey] = $ref;
    }

    public function chargeCount(): int
    {
        return $this->counter;
    }
}
