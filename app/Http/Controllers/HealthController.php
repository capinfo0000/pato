<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ヘルスチェック。ロードバランサ・監視から叩く。
 *
 * 依存（DB・キャッシュ/キュー）が生きているかまで見る。
 * 内部情報は出さない（バージョンや接続文字列を漏らさない）。
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function () {
                Cache::put('health:ping', '1', 5);

                return Cache::get('health:ping') === '1';
            }),
        ];

        $ok = ! in_array(false, $checks, true);

        return response()->json(
            ['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks],
            $ok ? 200 : 503,
        );
    }

    private function check(callable $probe): bool
    {
        try {
            $result = $probe();

            return $result !== false;
        } catch (\Throwable $e) {
            Log::error('health.check_failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
