<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Pricing\Contracts\PriceTableRepository;
use App\Domain\Pricing\Enums\CastClass;
use App\Domain\Pricing\Support\AreaClassPrice as PriceValue;
use App\Models\AreaClassPrice as PriceModel;

/**
 * area_class_prices テーブルから、PricingCalculator が使う料金表を組み立てる。
 * 最新の effective_from を採用する。
 */
final class EloquentPriceTableRepository implements PriceTableRepository
{
    public function forArea(int $areaId): array
    {
        $rows = PriceModel::query()
            ->with('classTier')
            ->where('area_id', $areaId)
            ->orderByDesc('effective_from')
            ->get();

        $table = [];
        foreach ($rows as $row) {
            $code = $row->classTier->code;
            if (isset($table[$code])) {
                continue; // 最新のみ採用
            }
            $table[$code] = new PriceValue(
                class: CastClass::from($code),
                pointsPer30min: $row->points_per_30min,
                takeRateBp: $row->take_rate_bp,
                nominationSurchargeBp: $row->nomination_surcharge_bp,
                nightSurchargeBp: $row->night_surcharge_bp,
            );
        }

        return $table;
    }
}
