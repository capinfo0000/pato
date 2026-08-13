<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Payout extends Model
{
    protected $fillable = ['cast_wallet_id', 'amount_points', 'status', 'speed', 'requested_at', 'paid_at'];

    protected $casts = [
        'amount_points' => 'integer',
        'requested_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(PointWallet::class, 'cast_wallet_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }
}
