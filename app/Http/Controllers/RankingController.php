<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CastProfile;
use App\Models\Ranking;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * ランキング（ゲスト / キャスト）。期間タブで切り替え、自分の順位（圏外含む）も表示する。
 */
final class RankingController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'subject' => ['nullable', 'in:guest,cast'],
            'period' => ['nullable', 'in:yesterday,last_week,last_month,this_month,half,year,all'],
        ]);
        $subject = $filters['subject'] ?? 'cast';
        $period = $filters['period'] ?? 'this_month';

        $rows = Ranking::where('subject', $subject)
            ->where('period', $period)
            ->orderBy('rank')
            ->limit(50)
            ->get();

        // 表示名の解決（キャストはプロフィール、ゲストはニックネーム）
        $names = $subject === 'cast'
            ? CastProfile::whereIn('id', $rows->pluck('ref_id'))->pluck('display_name', 'id')
            : User::whereIn('id', $rows->pluck('ref_id'))->pluck('nickname', 'id');

        $myRefId = $subject === 'cast'
            ? CastProfile::where('user_id', Auth::id())->value('id')
            : Auth::id();
        $myRow = $myRefId === null ? null : $rows->firstWhere('ref_id', $myRefId)
            ?? Ranking::where('subject', $subject)->where('period', $period)
                ->where('ref_id', $myRefId)->first();

        return view('rankings.index', [
            'rows' => $rows,
            'names' => $names,
            'subject' => $subject,
            'period' => $period,
            'myRow' => $myRow,
            'myRefId' => $myRefId,
            'balance' => 0,
        ]);
    }
}
