<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * キャストの評価指標。星は x10 の整数で保持し、表示時に 1 桁小数へ戻す。
 */
final class CastKpi extends Model
{
    protected $fillable = [
        'cast_profile_id', 'extend_rate_x10', 'repeat_rate_x10',
        'remeet_rate_x10', 'fan_points_total', 'recalculated_at',
    ];

    protected $casts = [
        'extend_rate_x10' => 'integer',
        'repeat_rate_x10' => 'integer',
        'remeet_rate_x10' => 'integer',
        'fan_points_total' => 'integer',
        'recalculated_at' => 'datetime',
    ];

    public function castProfile(): BelongsTo
    {
        return $this->belongsTo(CastProfile::class);
    }

    public function extendRate(): float
    {
        return $this->extend_rate_x10 / 10;
    }

    public function repeatRate(): float
    {
        return $this->repeat_rate_x10 / 10;
    }

    public function remeetRate(): float
    {
        return $this->remeet_rate_x10 / 10;
    }
}
