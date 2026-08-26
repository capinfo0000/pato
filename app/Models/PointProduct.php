<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PointProduct extends Model
{
    protected $fillable = ['paid_points', 'price_yen', 'active'];

    protected $casts = [
        'paid_points' => 'integer',
        'price_yen' => 'integer',
        'active' => 'boolean',
    ];
}
