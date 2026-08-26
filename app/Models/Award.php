<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 大会・シーズン企画で得た称号。 */
final class Award extends Model
{
    protected $fillable = ['cast_profile_id', 'event_code', 'title', 'season'];

    public function castProfile(): BelongsTo
    {
        return $this->belongsTo(CastProfile::class);
    }
}
