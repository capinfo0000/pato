<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// 成立待ちのまま時間切れになった呼び出しの与信を解放する
Schedule::command('pato:expire-calls')->everyFiveMinutes();

// ランキング集計（日次）
Schedule::command('pato:rankings --period=this_month')->dailyAt('04:00');
Schedule::command('pato:rankings --period=all')->dailyAt('04:10');
