<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ゲストがキャストへ送る称賛バッジの種別。 */
final class Badge extends Model
{
    protected $fillable = ['code', 'name'];
}
