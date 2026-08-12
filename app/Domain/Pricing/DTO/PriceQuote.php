<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTO;

/**
 * 料金見積もりの結果。
 * - guestHoldPoints: ゲストがホールド（与信）される有償ポイント（1P=¥1.2）
 * - castPayoutPoints: キャストへの報酬合計（1P=¥1 相当のポイント）
 * - takePoints: 運営取り分の目安（円相当をポイント換算した参考値）
 */
final readonly class PriceQuote
{
    public function __construct(
        public int $guestHoldPoints,
        public int $castPayoutPoints,
        public int $takePoints,
    ) {
    }

    /** ゲスト支払の円換算（1P=¥1.2） */
    public function guestYen(): int
    {
        return (int) round($this->guestHoldPoints * 1.2);
    }
}
