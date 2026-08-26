<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Point\Enums\PointKind;
use App\Domain\Point\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 台帳行（Eloquent）。ドメインの値オブジェクト
 * App\Domain\Point\Support\PointTransaction とは別物（永続化用）。
 */
final class PointTransaction extends Model
{
    protected $fillable = [
        'wallet_id', 'type', 'kind', 'points', 'call_id', 'product_id', 'expires_on', 'idempotency_key',
    ];

    protected $casts = [
        'type' => TransactionType::class,
        'kind' => PointKind::class,
        'points' => 'integer',
        'expires_on' => 'date',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(PointWallet::class, 'wallet_id');
    }
}
