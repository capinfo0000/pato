<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Ranking\RankingService;
use Illuminate\Console\Command;

/** ランキングを集計する（日次バッチ）。 */
final class ComputeRankings extends Command
{
    protected $signature = 'pato:rankings {--period=this_month}';

    protected $description = 'ランキングを集計して rankings テーブルに保存する';

    public function handle(RankingService $service): int
    {
        $result = $service->compute((string) $this->option('period'));
        $this->info("集計完了: キャスト {$result['cast']}件 / ゲスト {$result['guest']}件");

        return self::SUCCESS;
    }
}
