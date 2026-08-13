<?php

declare(strict_types=1);

namespace App\Application\Ranking;

use App\Domain\Call\Enums\CallStatus;
use App\Models\Call;
use App\Models\CastKpi;
use App\Models\FanPoint;
use App\Models\Ranking;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * ランキングとファンポイントの集計。日次バッチ（Job/スケジューラ）から呼ぶ想定。
 *
 * - キャスト: ファンポイント合計でランク付け
 * - ゲスト: 期間内の確定利用ポイント（capture）でランク付け
 *
 * 供給（キャスト）が薄い立ち上げ期は過疎感が出るため、表示は MVP では KPI 中心にし、
 * ランキング公開は供給が育ってから段階導入する（docs/00 §4.11）。
 */
final class RankingService
{
    /** ファンポイントの付与レート（完了1件あたり、100Pごとに1FP）。 */
    public const FAN_POINTS_PER_100 = 1;

    /** 完了した呼び出しからキャストにファンポイントを付与する。 */
    public function awardFanPoints(Call $call, int $castProfileId, int $payoutPoints): FanPoint
    {
        $points = intdiv($payoutPoints, 100) * self::FAN_POINTS_PER_100;

        $fp = FanPoint::create([
            'cast_profile_id' => $castProfileId,
            'call_id' => $call->id,
            'points' => $points,
            'reason' => 'completed_call',
        ]);

        $kpi = CastKpi::firstOrCreate(['cast_profile_id' => $castProfileId]);
        $kpi->increment('fan_points_total', $points);

        return $fp;
    }

    /**
     * 指定期間のランキングを集計して rankings に保存する。
     *
     * @return array{cast:int, guest:int} 生成した行数
     */
    public function compute(string $period = 'this_month'): array
    {
        [$from, $to] = $this->range($period);
        $today = now()->toDateString();

        // キャスト: 期間内のファンポイント合計
        $castRows = FanPoint::query()
            ->select('cast_profile_id', DB::raw('SUM(points) as score'))
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('cast_profile_id')
            ->orderByDesc('score')
            ->get();

        Ranking::where('subject', 'cast')->where('period', $period)->delete();
        foreach ($castRows as $i => $row) {
            Ranking::create([
                'subject' => 'cast',
                'period' => $period,
                'category' => '総合',
                'ref_id' => $row->cast_profile_id,
                'rank' => $i + 1,
                'score' => (int) $row->score,
                'computed_on' => $today,
            ]);
        }

        // ゲスト: 期間内に完了した呼び出しの利用ポイント合計
        $guestRows = Call::query()
            ->select('guest_user_id', DB::raw('SUM(hold_points) as score'))
            ->where('status', CallStatus::Completed)
            ->whereBetween('updated_at', [$from, $to])
            ->groupBy('guest_user_id')
            ->orderByDesc('score')
            ->get();

        Ranking::where('subject', 'guest')->where('period', $period)->delete();
        foreach ($guestRows as $i => $row) {
            Ranking::create([
                'subject' => 'guest',
                'period' => $period,
                'category' => '総合',
                'ref_id' => $row->guest_user_id,
                'rank' => $i + 1,
                'score' => (int) $row->score,
                'computed_on' => $today,
            ]);
        }

        return ['cast' => $castRows->count(), 'guest' => $guestRows->count()];
    }

    /** @return array{0:CarbonInterface,1:CarbonInterface} */
    private function range(string $period): array
    {
        return match ($period) {
            'yesterday' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'last_week' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
            'half' => [now()->startOfYear(), now()->endOfYear()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => [now()->subYears(10), now()->endOfDay()], // all
        };
    }
}
