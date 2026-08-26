<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\MetricsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** 運営ダッシュボード。GMV・テイクレート実績・供給状況・要対応件数。 */
final class DashboardController extends Controller
{
    public function index(Request $request, MetricsService $metrics): View
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:this_month,last_month,all'],
        ]);
        $period = $validated['period'] ?? 'this_month';

        [$from, $to] = match ($period) {
            'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'all' => [Carbon::createFromTimestamp(0), now()->endOfDay()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };

        return view('admin.dashboard', [
            'summary' => $metrics->summary($from, $to),
            'breakdown' => $metrics->callBreakdown($from, $to),
            'period' => $period,
            'balance' => 0,
        ]);
    }
}
