<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kpi(): HasOne
    {
        return $this->hasOne(CastKpi::class);
    }

    public function fanPoints(): HasMany
    {
        return $this->hasMany(FanPoint::class);
    }

    public function badgeGrants(): HasMany
    {
        return $this->hasMany(BadgeGrant::class);
    }

    public function awards(): HasMany
    {
        return $this->hasMany(Award::class);
    }

    /** 今すぐ呼べる（在席かつ対応中でない・承認済み）。 */
    public function scopeCallableNow($query)
    {
        return $query->where('is_active', true)
            ->where('availability', 'now')
            ->where('in_session', false);
    }
}
