<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * ロールは guest / cast / admin / system。実名は保持せず nickname で表示する。
 */
final class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'email', 'phone', 'password', 'role', 'status', 'nickname',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(PointWallet::class);
    }

    public function isGuest(): bool
    {
        return $this->role === 'guest';
    }

    public function isCast(): bool
    {
        return $this->role === 'cast';
    }
}
