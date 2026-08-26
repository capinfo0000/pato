<?php

declare(strict_types=1);

namespace App\Support\Adapters\Fake;

use App\Support\Contracts\ChargeResult;
use App\Support\Contracts\PaymentGateway;

/**
 * テスト/ローカル用の決済 Fake。冪等キーで二重課金を防ぐ挙動を模倣する。
 *
 * `$requiresAction` を立てると 3Dセキュアが必要なカードを模倣できる。
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, ChargeResult> idempotencyKey => 結果 */
    private array $charges = [];

    /** @var array<string, ChargeResult> reference => 結果 */
    private array $byReference = [];

    private int $counter = 0;

    public bool $shouldFail = false;

    public bool $requiresAction = false;

    public function charge(string $paymentMethodToken, int $amountYen, string $idempotencyKey): ChargeResult
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
        $result = new ChargeResult(
            status: $this->requiresAction ? ChargeResult::REQUIRES_ACTION : ChargeResult::SUCCEEDED,
            reference: $ref,
            amountYen: $amountYen,
            clientSecret: $ref.'_secret_fake',
        );

        $this->byReference[$ref] = $result;

        return $this->charges[$idempotencyKey] = $result;
    }

    public function confirm(string $reference): ChargeResult
    {
        if (! isset($this->byReference[$reference])) {
            throw new \RuntimeException('payment_lookup_failed');
        }

        $pending = $this->byReference[$reference];

        // requires_action のものはブラウザで認証を終えた後を模倣して確定させる。
        // それ以外（テストが仕込んだ未確定・金額違い）はそのまま返す
        return new ChargeResult(
            status: $pending->requiresAction() ? ChargeResult::SUCCEEDED : $pending->status,
            reference: $pending->reference,
            amountYen: $pending->amountYen,
            currency: $pending->currency,
        );
    }

    /** テスト用: 問い合わせ結果を差し替える（状態偽装・金額改ざんの検証に使う） */
    public function stubLookup(string $reference, string $status, int $amountYen = 0): void
    {
        $this->byReference[$reference] = new ChargeResult(
            status: $status,
            reference: $reference,
            amountYen: $amountYen,
        );
    }

    public function chargeCount(): int
    {
        return $this->counter;
    }
}
