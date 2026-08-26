<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AreaClassPrice extends Model
{
    protected $fillable = [
        'area_id', 'class_tier_id', 'points_per_30min',
        'take_rate_bp', 'nomination_surcharge_bp', 'night_surcharge_bp', 'effective_from',
    ];

    protected $casts = [
        'points_per_30min' => 'integer',
        'take_rate_bp' => 'integer',
        'nomination_surcharge_bp' => 'integer',
        'night_surcharge_bp' => 'integer',
        'effective_from' => 'date',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function classTier(): BelongsTo
    {
        return $this->belongsTo(ClassTier::class);
    }
}
