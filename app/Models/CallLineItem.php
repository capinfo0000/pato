<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CallLineItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['call_id', 'class_tier_id', 'headcount', 'nominated'];

    protected $casts = [
        'headcount' => 'integer',
        'nominated' => 'boolean',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function classTier(): BelongsTo
    {
        return $this->belongsTo(ClassTier::class);
    }
}
