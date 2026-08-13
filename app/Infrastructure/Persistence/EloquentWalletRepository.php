<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Point\Contracts\WalletRepository;
use App\Domain\Point\Support\PointTransaction as PointTransactionValue;
use App\Domain\Point\Support\Wallet;
use App\Models\PointTransaction as PointTransactionModel;
use App\Models\PointWallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * point_transactions テーブルとドメインの Wallet を橋渡しする。
 * 読み込みは行→値オブジェクト、追記は冪等キー付きで1行 insert。
 */
final class EloquentWalletRepository implements WalletRepository
{
    public function load(int $walletId): Wallet
    {
        return $this->hydrate($this->rows($walletId));
    }

    public function loadForUpdate(int $walletId): Wallet
    {
        if (DB::transactionLevel() === 0) {
            // トランザクション外ではロックが即解放され、意味を成さない
            throw new \LogicException('loadForUpdate は DB トランザクションの内側で呼ぶこと');
        }

        // ウォレット行を排他ロックし、同一ウォレットへの引き落としを直列化する。
        // 台帳行ではなくウォレット行を掴むのは、まだ存在しない行（これから insert する
        // 台帳）に対してもロックを効かせるため（ギャップロックに頼らない）。
        PointWallet::query()->whereKey($walletId)->lockForUpdate()->first();

        return $this->hydrate($this->rows($walletId));
    }

    public function append(int $walletId, PointTransactionValue $tx, string $idempotencyKey): void
    {
        PointTransactionModel::query()->create([
            'wallet_id' => $walletId,
            'type' => $tx->type,
            'kind' => $tx->kind,
            'points' => $tx->points,
            'call_id' => $tx->callId,
            'expires_on' => $tx->expiresOn,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /** @return Collection<int, PointTransactionModel> */
    private function rows(int $walletId): Collection
    {
        return PointTransactionModel::query()
            ->where('wallet_id', $walletId)
            ->orderBy('id')
            ->get();
    }

    /** @param Collection<int, PointTransactionModel> $rows */
    private function hydrate(Collection $rows): Wallet
    {
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
}
