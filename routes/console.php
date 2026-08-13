<?php

declare(strict_types=1);

use App\Jobs\ComputeRankingsJob;
use App\Jobs\ExpireStaleCallsJob;
use Illuminate\Support\Facades\Schedule;

/*
 * 定期実行。いずれもキュー経由で流し、スケジューラのプロセスを塞がない。
 * withoutOverlapping で多重起動時の重複実行を防ぐ。
 */

// 与信の解放は遅れるとゲストの残高が使えないままになるので短い間隔で
Schedule::job(new ExpireStaleCallsJob)->everyFiveMinutes()->withoutOverlapping();

// ランキング集計（日次）
Schedule::job(new ComputeRankingsJob('this_month'))->dailyAt('04:00')->withoutOverlapping();
Schedule::job(new ComputeRankingsJob('all'))->dailyAt('04:10')->withoutOverlapping();
