<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ポイント購入の記録。返金時に回収対象を特定するために使う。 */
final class PointPurchase extends Model
{
    protected $fillable = [
        'wallet_id', 'product_id', 'paid_points', 'price_yen',
        'charge_ref', 'refunded_at', 'refunded_points',
    ];

    protected $casts = [
        'paid_points' => 'integer',
        'price_yen' => 'integer',
        'refunded_points' => 'integer',
        'refunded_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(PointWallet::class, 'wallet_id');
    }

    public function isRefunded(): bool
    {
        return $this->refunded_at !== null;
    }
}
