<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Call\CallLifecycleService;
use Illuminate\Console\Command;

/** 成立しないまま開始時刻を過ぎた呼び出しを不成立にし、与信を解放する。 */
final class ExpireStaleCalls extends Command
{
    protected $signature = 'pato:expire-calls';

    protected $description = '成立待ちのまま時間切れになった呼び出しを不成立にして与信を解放する';

    public function handle(CallLifecycleService $lifecycle): int
    {
        $count = $lifecycle->expireStaleCalls();
        $this->info("不成立にした呼び出し: {$count}件");

        return self::SUCCESS;
    }
}
