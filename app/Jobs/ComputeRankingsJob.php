<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Ranking\RankingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** ランキング集計。件数が増えると重いのでキューで回す。 */
final class ComputeRankingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $period = 'this_month') {}

    public function handle(RankingService $service): void
    {
        $service->compute($this->period);
    }
}
