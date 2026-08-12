<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use App\Domain\Pricing\Enums\CastClass;

/**
 * エリア×クラスの料金設定（AREA_CLASS_PRICES 相当）。
 * points_per_30min はゲスト課金（有償ポイント, 1P=¥1.2）。
 * take_rate_bp は運営取り分（千分率）。既定 4000 = 40%。
 */
final readonly class AreaClassPrice
{
    public function __construct(
        public CastClass $class,
        public int $pointsPer30min,
        public int $takeRateBp = 4000,
        public int $nominationSurchargeBp = 2000,
        public int $nightSurchargeBp = 2000,
    ) {
        foreach (['pointsPer30min' => $pointsPer30min] as $name => $v) {
            if ($v < 0) {
                throw new \InvalidArgumentException("{$name} は0以上");
            }
        }
        foreach (['takeRateBp' => $takeRateBp, 'nominationSurchargeBp' => $nominationSurchargeBp, 'nightSurchargeBp' => $nightSurchargeBp] as $name => $bp) {
            if ($bp < 0 || $bp > 10000) {
                throw new \InvalidArgumentException("{$name} は 0〜10000 bp");
            }
        }
    }
}
