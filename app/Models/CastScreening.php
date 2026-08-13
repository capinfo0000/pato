<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** キャスト審査の各段階の記録（写真 / 面談）。 */
final class CastScreening extends Model
{
    protected $fillable = [
        'cast_profile_id', 'stage', 'result', 'reviewed_by', 'note', 'reviewed_at',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function castProfile(): BelongsTo
    {
        return $this->belongsTo(CastProfile::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
