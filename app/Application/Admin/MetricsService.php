<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Domain\Call\Enums\CallStatus;
use App\Models\Call;
use App\Models\CastProfile;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\Report;
use App\Models\SosEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * 運営ダッシュボードの指標。
 *
 * GMV はポイントではなく円換算（1有償ポイント = ¥1.2）で見る。
 * テイクレートは「設定値」ではなく、実際の完了データから逆算した実績値を出す。
 */
final class MetricsService
{
    private const YEN_PER_PAID_POINT_X10 = 12;

    /**
     * @return array{
     *   completed:int, gmv_points:int, gmv_yen:int, payout_points:int,
     *   take_points:int, take_rate_pct:float, avg_call_points:int,
     *   active_casts:int, standby_casts:int, guests:int,
     *   open_reports:int, open_sos:int, pending_payout_points:int
     * }
     */
    public function summary(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= now()->startOfMonth();
        $to ??= now()->endOfMonth();

        $completed = Call::where('status', CallStatus::Completed)
            ->whereBetween('updated_at', [$from, $to]);

        $count = (clone $completed)->count();
        $gmvPoints = (int) (clone $completed)->sum('hold_points');
        $payoutPoints = (int) (clone $completed)->sum('cast_payout_points');

        // ゲスト支払は有償ポイント(¥1.2/P)、キャスト報酬は円相当ポイント(¥1/P)
        $gmvYen = (int) round($gmvPoints * self::YEN_PER_PAID_POINT_X10 / 10);
        $takePoints = $gmvYen - $payoutPoints;

        return [
            'completed' => $count,
            'gmv_points' => $gmvPoints,
            'gmv_yen' => $gmvYen,
            'payout_points' => $payoutPoints,
            'take_points' => $takePoints,
            'take_rate_pct' => $gmvYen > 0 ? round($takePoints / $gmvYen * 100, 1) : 0.0,
            'avg_call_points' => $count > 0 ? intdiv($gmvPoints, $count) : 0,
            'active_casts' => CastProfile::where('is_active', true)->count(),
            'standby_casts' => CastProfile::callableNow()->count(),
            'guests' => User::where('role', 'guest')->where('status', 'active')->count(),
            'open_reports' => Report::where('status', 'open')->count(),
            'open_sos' => SosEvent::where('status', 'open')->count(),
            'pending_payout_points' => (int) PayoutItem::whereNull('payout_id')->sum('amount_points')
                + (int) Payout::whereIn('status', ['requested', 'approved'])->sum('amount_points'),
        ];
    }

    /**
     * 呼び出しのステータス内訳（運用の詰まりを見る）。
     *
     * @return array<string,int>
     */
    public function callBreakdown(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= now()->startOfMonth();
        $to ??= now()->endOfMonth();

        return Call::whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
    }
}
