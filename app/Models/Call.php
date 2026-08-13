<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Call\Enums\CallStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Call extends Model
{
    protected $fillable = [
        'guest_user_id', 'area_id', 'venue_id', 'start_at', 'duration_min', 'headcount',
        'hold_points', 'status', 'is_mix', 'nominated_cast_profile_id',
        'priority_surcharge_points', 'is_night', 'venue_kind', 'note',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'duration_min' => 'integer',
        'headcount' => 'integer',
        'hold_points' => 'integer',
        'status' => CallStatus::class,
        'is_mix' => 'boolean',
        'is_night' => 'boolean',
        'priority_surcharge_points' => 'integer',
    ];

    public function guest(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guest_user_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(CallLineItem::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CallParticipant::class);
    }
}
