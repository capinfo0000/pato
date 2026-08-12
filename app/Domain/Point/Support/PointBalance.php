<?php

declare(strict_types=1);

namespace App\Domain\Point\Support;

/**
 * ポイント残高を「台帳（PointTransaction の列）」から算出する純粋ロジック。
 *
 * - settled（確定残高）  = 購入/付与 − 確定消費（capture/tip/expire）
 * - outstandingHold      = 未解消の与信 = hold − (capture + release)
 * - available（利用可能） = settled − outstandingHold
 *
 * 呼び出し作成時は available を見てホールド可否を判定する。
 */
final class PointBalance
{
    /** @param list<PointTransaction> $transactions */
    public function __construct(private readonly array $transactions)
    {
    }

    /** 確定残高（実際に消費・保有しているポイント） */
    public function settled(): int
    {
        $sum = 0;
        foreach ($this->transactions as $t) {
            $sum += $t->type->settledSign() * $t->points;
        }

        return $sum;
    }

    /** 未解消の与信（ホールド）合計 */
    public function outstandingHold(): int
    {
        $sum = 0;
        foreach ($this->transactions as $t) {
            $sum += $t->type->holdSign() * $t->points;
        }

        return $sum;
    }

    /** 新規のホールドに使える残高 */
    public function available(): int
    {
        return $this->settled() - $this->outstandingHold();
    }

    /** amount ポイントを新たにホールドできるか */
    public function canHold(int $amount): bool
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('amount は0以上');
        }

        return $this->available() >= $amount;
    }
}
