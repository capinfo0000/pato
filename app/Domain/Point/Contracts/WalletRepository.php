<?php

declare(strict_types=1);

namespace App\Domain\Point\Contracts;

use App\Domain\Point\Support\PointTransaction;
use App\Domain\Point\Support\Wallet;

/**
 * ポイントウォレットの永続化契約。台帳（point_transactions）を読み書きする。
 * 残高は台帳から算出し、残高カラムは持たない。
 */
interface WalletRepository
{
    /** 台帳を読み込んでインメモリの Wallet を復元する。 */
    public function load(int $walletId): Wallet;

    /**
     * 1件の台帳行を追記する。idempotency_key で二重計上を防ぐ。
     */
    public function append(int $walletId, PointTransaction $tx, string $idempotencyKey): void;
}
