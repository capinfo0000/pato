<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 完了した呼び出しへの相互評価（星＋定型タグ）。 */
final class Review extends Model
{
    protected $fillable = ['call_id', 'rater_user_id', 'ratee_user_id', 'stars', 'tags', 'comment'];

    protected $casts = [
        'stars' => 'integer',
        'tags' => 'array',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rater_user_id');
    }

    public function ratee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ratee_user_id');
    }
}
