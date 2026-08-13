<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 処理済み Webhook イベント。一意制約が二重処理の最終防波堤になる。 */
final class WebhookEvent extends Model
{
    protected $fillable = ['provider', 'event_id', 'type'];
}
