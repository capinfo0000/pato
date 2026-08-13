<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cast;

use App\Application\Call\CallLifecycleService;
use App\Domain\Call\Enums\CallStatus;
use App\Domain\Point\Contracts\WalletRepository;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\CastProfile;
use App\Models\PointWallet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * キャスト側の画面。募集一覧 → 参加表明、在席ステータス切替、報酬確認。
 *
 * 参加は完全に任意（運営からの割当・強制はしない）。職安法上の指揮命令性を持たせない設計。
 * docs/04_legal_compliance.md §3 参照。
 */
final class CastDashboardController extends Controller
{
    /** 募集中の呼び出し一覧（自分のクラス・エリアに合致するもの）。 */
    public function index(): View|RedirectResponse
    {
        $cast = CastProfile::where('user_id', Auth::id())->first();

        // 未申込・審査中・却下はダッシュボードを開けない（申込/結果待ちへ誘導）
        if ($cast === null || $cast->screening_status !== 'approved') {
            return redirect()->route('cast.apply.show');
        }

        $openCalls = Call::query()
            ->where('status', CallStatus::Open)
            ->where('area_id', $cast->home_area_id)
            ->whereHas('lineItems', fn ($q) => $q->where('class_tier_id', $cast->class_tier_id))
            ->whereDoesntHave('participants', fn ($q) => $q->where('cast_profile_id', $cast->id))
            ->with('area', 'lineItems.classTier')
            ->latest()
            ->get();

        $myCalls = Call::query()
            ->whereHas('participants', fn ($q) => $q->where('cast_profile_id', $cast->id))
            ->whereIn('status', [CallStatus::Matched, CallStatus::InProgress])
            ->with('area')
            ->get();

        return view('cast.index', [
            'cast' => $cast,
            'openCalls' => $openCalls,
            'myCalls' => $myCalls,
            'earned' => $this->earnedPoints(),
            'balance' => $this->earnedPoints(),
        ]);
    }

    /** 参加表明（自由意思）。定員に達すれば成立。 */
    public function participate(Call $call, CallLifecycleService $lifecycle): RedirectResponse
    {
        try {
            $matched = $lifecycle->participate($call, $this->cast());
        } catch (\DomainException $e) {
            return back()->with('error', match ($e->getMessage()) {
                'call_not_open' => 'この募集はすでに終了しています。',
                'cast_in_session' => '合流中は新しい募集に参加できません。',
                'already_participating' => 'すでに参加表明済みです。',
                'cast_not_approved' => '審査通過後にご参加いただけます。',
                default => '参加できませんでした。',
            });
        }

        return back()->with('status', $matched
            ? '成立しました。集合場所と時間をご確認ください。'
            : '参加表明しました。他のキャストが揃うまでお待ちください。');
    }

    /** 在席ステータスの切替（今すぐ可 / 本日可 / オフライン）。 */
    public function availability(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'availability' => ['required', Rule::in(['now', 'today', 'offline'])],
        ]);

        $this->cast()->update(['availability' => $validated['availability']]);

        return back()->with('status', '在席ステータスを更新しました。');
    }

    private function cast(): CastProfile
    {
        return CastProfile::where('user_id', Auth::id())->firstOrFail();
    }

    private function earnedPoints(): int
    {
        $wallet = PointWallet::where('user_id', Auth::id())->first();

        return $wallet === null
            ? 0
            : app(WalletRepository::class)->load($wallet->id)->balance()->available();
    }
}
