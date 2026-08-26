<?php

declare(strict_types=1);

namespace App\Domain\Point\Support;

/**
 * ウォレット集約（インメモリ）。台帳（PointTransaction の列）を保持し、
 * 追記のみで残高を動かす。残高は常に PointBalance で算出する。
 *
 * 永続化層（Laravel の Eloquent）ではこの列を point_transactions テーブルに落とす。
 */
final class Wallet
{
    /** @var list<PointTransaction> */
    private array $transactions;

    /** @param list<PointTransaction> $transactions */
    public function __construct(array $transactions = [])
    {
        $this->transactions = array_values($transactions);
    }

    public function append(PointTransaction $tx): void
    {
        $this->transactions[] = $tx;
    }

    public function balance(): PointBalance
    {
        return new PointBalance($this->transactions);
    }

    /** @return list<PointTransaction> */
    public function transactions(): array
    {
        return $this->transactions;
    }
}
