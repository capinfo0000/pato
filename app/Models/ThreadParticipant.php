<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** スレッドの参加者（ゲスト・キャスト・コンシェルジュ）。 */
final class ThreadParticipant extends Model
{
    protected $fillable = ['thread_id', 'user_id', 'is_favorite', 'is_hidden', 'last_read_at'];

    protected $casts = [
        'is_favorite' => 'boolean',
        'is_hidden' => 'boolean',
        'last_read_at' => 'datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
