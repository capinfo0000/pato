<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 通報。運営が確認し、必要に応じてアカウント制裁を行う。 */
final class Report extends Model
{
    protected $fillable = [
        'reporter_user_id', 'target_user_id', 'call_id', 'reason', 'status', 'detail',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
