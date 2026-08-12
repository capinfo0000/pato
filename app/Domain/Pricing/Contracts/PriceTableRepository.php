<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Contracts;

use App\Domain\Pricing\Support\AreaClassPrice;

/**
 * エリアの料金表を供給する契約。実装は Eloquent（area_class_prices）。
 */
interface PriceTableRepository
{
    /**
     * @return array<string, AreaClassPrice> class value => 料金設定
     */
    public function forArea(int $areaId): array;
}
