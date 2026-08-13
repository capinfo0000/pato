<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CallParticipant extends Model
{
    protected $fillable = ['call_id', 'cast_profile_id', 'status', 'tip_points', 'joined_at'];

    protected $casts = [
        'tip_points' => 'integer',
        'joined_at' => 'datetime',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function castProfile(): BelongsTo
    {
        return $this->belongsTo(CastProfile::class);
    }
}
