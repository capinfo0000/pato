<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Area extends Model
{
    protected $fillable = ['name', 'serviceable'];

    protected $casts = ['serviceable' => 'boolean'];
}
