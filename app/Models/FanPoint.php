<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ファンポイント（キャストの独自評価スコアの元帳）。 */
final class FanPoint extends Model
{
    protected $fillable = ['cast_profile_id', 'call_id', 'points', 'reason'];

    protected $casts = ['points' => 'integer'];

    public function castProfile(): BelongsTo
    {
        return $this->belongsTo(CastProfile::class);
    }
}
