<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SOS通報。合流中の緊急連絡。 */
final class SosEvent extends Model
{
    protected $fillable = [
        'user_id', 'call_id', 'status', 'note', 'location_hint',
        'handled_by', 'acknowledged_at', 'resolved_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    // 位置情報は PII。一覧などで不用意に出さない
    protected $hidden = ['location_hint'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
