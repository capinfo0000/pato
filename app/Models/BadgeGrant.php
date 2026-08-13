<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** バッジの付与履歴（ゲスト → キャスト）。 */
final class BadgeGrant extends Model
{
    protected $fillable = ['badge_id', 'from_user_id', 'cast_profile_id', 'call_id'];

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    public function castProfile(): BelongsTo
    {
        return $this->belongsTo(CastProfile::class);
    }
}
