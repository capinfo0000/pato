<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * スレッド。呼び出し単位のグループチャット / 個別 / コンシェルジュ(公式)。
 */
final class Thread extends Model
{
    protected $fillable = ['call_id', 'kind', 'is_favorite', 'is_hidden', 'last_message_at'];

    protected $casts = [
        'is_favorite' => 'boolean',
        'is_hidden' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ThreadParticipant::class);
    }

    public function isConcierge(): bool
    {
        return $this->kind === 'concierge';
    }
}
