<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\DTO\CallLineItem;
use App\Domain\Pricing\DTO\PriceQuote;
use App\Domain\Pricing\Enums\CastClass;
use App\Domain\Pricing\Support\AreaClassPrice;

/**
 * エリア別・クラス別のパトコール料金と、キャスト報酬・運営取り分を算出する純粋ロジック。
 *
 * 料金 = クラスの30分単価 × コマ数（duration/30）× 人数、指名/深夜の加算（bp）を反映。
 * ミックス（複数クラス）は明細ごとに合算する。
 * キャスト報酬 = ゲスト支払(円換算) × キャスト取り分(1 - take_rate)。
 *
 * すべて整数（ポイント）で扱い、割合計算のみ最後に四捨五入する。
 */
final class PricingCalculator
{
    private const YEN_PER_PAID_POINT_X10 = 12; // 1P = ¥1.2 → ×10 表現で 12

    /**
     * @param  array<string, AreaClassPrice>  $priceTable  class value => 料金設定
     * @param  list<CallLineItem>  $items
     */
    public function quote(array $priceTable, array $items, int $durationMin, bool $isNight = false): PriceQuote
    {
        if ($durationMin <= 0 || $durationMin % 30 !== 0) {
            throw new \InvalidArgumentException('durationMin は30の正の倍数');
        }
        if ($items === []) {
            throw new \InvalidArgumentException('明細が空');
        }

        $slots = intdiv($durationMin, 30);
        $guestHoldPoints = 0;
        $castPayoutPoints = 0;

        foreach ($items as $item) {
            $price = $priceTable[$item->class->value]
                ?? throw new \InvalidArgumentException("料金未設定のクラス: {$item->class->value}");

            $surchargeBp = ($item->nominated ? $price->nominationSurchargeBp : 0)
                + ($isNight ? $price->nightSurchargeBp : 0);

            // 1名あたりのゲスト支払（有償ポイント）
            $base = $price->pointsPer30min * $slots;
            $perHeadGuest = $this->applyBp($base, 10000 + $surchargeBp);

            // 1名あたりのキャスト報酬（円相当ポイント, 1P=¥1）
            $perHeadGuestYen = (int) round($perHeadGuest * self::YEN_PER_PAID_POINT_X10 / 10);
            $castShareBp = 10000 - $price->takeRateBp;
            $perHeadCast = $this->applyBp($perHeadGuestYen, $castShareBp);

            $guestHoldPoints += $perHeadGuest * $item->headcount;
            $castPayoutPoints += $perHeadCast * $item->headcount;
        }

        $guestYen = (int) round($guestHoldPoints * self::YEN_PER_PAID_POINT_X10 / 10);
        $takePoints = $guestYen - $castPayoutPoints;

        return new PriceQuote($guestHoldPoints, $castPayoutPoints, $takePoints);
    }

    /** base × (bp/10000) を四捨五入して整数で返す */
    private function applyBp(int $base, int $bp): int
    {
        return (int) round($base * $bp / 10000);
    }

    /**
     * 岡山（地方水準）の既定料金表。docs/06_pricing_model.md 準拠。
     *
     * @return array<string, AreaClassPrice>
     */
    public static function okayamaDefaultTable(): array
    {
        return [
            CastClass::Premium->value => new AreaClassPrice(CastClass::Premium, 3000),
            CastClass::Vip->value => new AreaClassPrice(CastClass::Vip, 5500),
            CastClass::RoyalVip->value => new AreaClassPrice(CastClass::RoyalVip, 10000),
        ];
    }
}
