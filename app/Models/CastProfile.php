<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CastProfile extends Model
{
    protected $fillable = [
        'user_id', 'display_name', 'class_tier_id', 'home_area_id',
        'screening_status', 'is_active', 'availability', 'in_session', 'bio', 'age',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'in_session' => 'boolean',
        'age' => 'integer',
    ];

    public function classTier(): BelongsTo
    {
        return $this->belongsTo(ClassTier::class);
    }

    public function homeArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'home_area_id');
    }

    /** 今すぐ呼べる（在席かつ対応中でない・承認済み）。 */
    public function scopeCallableNow($query)
    {
        return $query->where('is_active', true)
            ->where('availability', 'now')
            ->where('in_session', false);
    }
}
