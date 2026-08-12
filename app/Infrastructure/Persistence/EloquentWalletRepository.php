<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Point\Contracts\WalletRepository;
use App\Domain\Point\Support\PointTransaction as PointTransactionValue;
use App\Domain\Point\Support\Wallet;
use App\Models\PointTransaction as PointTransactionModel;

/**
 * point_transactions テーブルとドメインの Wallet を橋渡しする。
 * 読み込みは行→値オブジェクト、追記は冪等キー付きで1行 insert。
 */
final class EloquentWalletRepository implements WalletRepository
{
    public function load(int $walletId): Wallet
    {
        $rows = PointTransactionModel::query()
            ->where('wallet_id', $walletId)
            ->orderBy('id')
            ->get();

        $txs = [];
        foreach ($rows as $row) {
            $txs[] = new PointTransactionValue(
                type: $row->type,
                kind: $row->kind,
                points: $row->points,
                callId: $row->call_id,
            );
        }

        return new Wallet($txs);
    }

    public function append(int $walletId, PointTransactionValue $tx, string $idempotencyKey): void
    {
        PointTransactionModel::query()->create([
            'wallet_id' => $walletId,
            'type' => $tx->type,
            'kind' => $tx->kind,
            'points' => $tx->points,
            'call_id' => $tx->callId,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
