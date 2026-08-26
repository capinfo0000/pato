<?php

declare(strict_types=1);

namespace App\Domain\Point\Enums;

/**
 * ポイントの種別。無償ポイントは消費時に優先して減る（1P=¥1）。
 * 有償ポイントは 1P=¥1.2 相当。
 */
enum PointKind: string
{
    case Paid = 'paid';
    case Free = 'free';
}
