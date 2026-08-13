<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Call\CallLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 成立しないまま時間切れになった呼び出しの与信を解放する。
 * 与信が残り続けるとゲストの残高が使えないため、確実に回す必要がある。
 */
final class ExpireStaleCallsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(CallLifecycleService $lifecycle): void
    {
        $lifecycle->expireStaleCalls();
    }
}
