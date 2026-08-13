<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Point\Contracts\WalletRepository;
use App\Domain\Pricing\Contracts\PriceTableRepository;
use App\Models\Area;
use App\Models\CastProfile;
use App\Models\PointWallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 「探す」: キャスト一覧の絞り込み検索と詳細（選んで呼ぶ＝指名の起点）。
 */
final class CastDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'class' => ['nullable', 'in:premium,vip,royal_vip'],
            'age_min' => ['nullable', 'integer', 'min:18', 'max:99'],
            'age_max' => ['nullable', 'integer', 'min:18', 'max:99'],
            'availability' => ['nullable', 'in:now,today'],
            'q' => ['nullable', 'string', 'max:50'],
        ]);

        $casts = CastProfile::query()
            ->where('is_active', true)
            ->where('screening_status', 'approved')
            ->with('classTier', 'homeArea', 'kpi')
            ->when($filters['area_id'] ?? null, fn ($q, $v) => $q->where('home_area_id', $v))
            ->when($filters['class'] ?? null, fn ($q, $v) => $q->whereHas('classTier', fn ($t) => $t->where('code', $v)))
            ->when($filters['age_min'] ?? null, fn ($q, $v) => $q->where('age', '>=', $v))
            ->when($filters['age_max'] ?? null, fn ($q, $v) => $q->where('age', '<=', $v))
            ->when($filters['availability'] ?? null, fn ($q, $v) => $q->where('availability', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('display_name', 'like', "%{$v}%"))
            ->orderByRaw("CASE availability WHEN 'now' THEN 0 WHEN 'today' THEN 1 ELSE 2 END")
            ->paginate(24)
            ->withQueryString();

        return view('casts.index', [
            'casts' => $casts,
            'areas' => Area::where('serviceable', true)->get(),
            'filters' => $filters,
            'balance' => $this->availablePoints(),
        ]);
    }

    public function show(CastProfile $castProfile, PriceTableRepository $prices): View
    {
        abort_unless($castProfile->is_active && $castProfile->screening_status === 'approved', 404);

        $castProfile->load('classTier', 'homeArea', 'kpi', 'badgeGrants.badge', 'awards');

        // このキャストを指名した場合の 30分あたり料金（指名加算込み）
        $table = $prices->forArea((int) $castProfile->home_area_id);
        $price = $table[$castProfile->classTier?->code] ?? null;

        return view('casts.show', [
            'cast' => $castProfile,
            'pointsPer30min' => $price?->pointsPer30min,
            'nominationBp' => $price?->nominationSurchargeBp,
            'badgeCounts' => $castProfile->badgeGrants
                ->groupBy(fn ($g) => $g->badge?->name ?? '-')
                ->map->count()
                ->sortDesc(),
            'balance' => $this->availablePoints(),
        ]);
    }

    private function availablePoints(): int
    {
        $wallet = PointWallet::where('user_id', Auth::id())->first();

        return $wallet === null
            ? 0
            : app(WalletRepository::class)->load($wallet->id)->balance()->available();
    }
}
