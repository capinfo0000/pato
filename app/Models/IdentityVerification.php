<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IdentityVerification extends Model
{
    protected $fillable = [
        'user_id', 'method', 'status', 'is_adult', 'birthdate_encrypted', 'provider_ref', 'verified_at',
    ];

    protected $casts = [
        'is_adult' => 'boolean',
        'verified_at' => 'datetime',
        'birthdate_encrypted' => 'encrypted', // PII は暗号化
    ];

    protected $hidden = ['birthdate_encrypted', 'provider_ref'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
