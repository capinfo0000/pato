<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Enums;

enum CastClass: string
{
    case Premium = 'premium';
    case Vip = 'vip';
    case RoyalVip = 'royal_vip';

    public function label(): string
    {
        return match ($this) {
            self::Premium => 'プレミアム',
            self::Vip => 'VIP',
            self::RoyalVip => 'ロイヤルVIP',
        };
    }
}
