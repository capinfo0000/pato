<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PayoutItem extends Model
{
    protected $fillable = ['payout_id', 'call_participant_id', 'amount_points'];

    protected $casts = ['amount_points' => 'integer'];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(CallParticipant::class, 'call_participant_id');
    }
}
