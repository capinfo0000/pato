<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 集計済みランキング（日次バッチで算出）。 */
final class Ranking extends Model
{
    protected $fillable = ['subject', 'period', 'area_id', 'category', 'ref_id', 'rank', 'score', 'computed_on'];

    protected $casts = [
        'ref_id' => 'integer',
        'rank' => 'integer',
        'score' => 'integer',
        'computed_on' => 'date',
    ];
}
