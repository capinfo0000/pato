<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTO;

use App\Domain\Pricing\Enums\CastClass;

/**
 * 呼び出し1件に含まれる「クラス×人数」の明細。
 * 例: ロイヤルVIP 1名 ＋ VIP 1名（ミックス）は 2 行になる。
 */
final readonly class CallLineItem
{
    public function __construct(
        public CastClass $class,
        public int $headcount,
        public bool $nominated = false,
    ) {
        if ($headcount < 1) {
            throw new \InvalidArgumentException('headcount は1以上');
        }
    }
}
