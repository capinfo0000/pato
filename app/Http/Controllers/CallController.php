<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Call\CallLifecycleService;
use App\Application\Call\CreateCallService;
use App\Application\Trust\ReviewService;
use App\Domain\Point\Contracts\WalletRepository;
use App\Http\Requests\CreateCallRequest;
use App\Models\Area;
use App\Models\Call;
use App\Models\CastProfile;
use App\Models\PointWallet;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * patoコールの「呼ぶ」導線。ホーム → 条件入力 → 確認(初回注意喚起) → 作成(与信) → 成立待ち。
 */
final class CallController extends Controller
{
    public function __construct(private readonly CreateCallService $service) {}

    /** ホーム（今すぐ呼ぶ / 今日会えるキャスト）。 */
    public function home(): View
    {
        $area = Area::where('serviceable', true)->first();
        $standby = CastProfile::callableNow()->count();
        $todaysCasts = CastProfile::query()
            ->where('is_active', true)
            ->whereIn('availability', ['now', 'today'])
            ->with('classTier')
            ->limit(12)
            ->get();

        return view('calls.home', [
            'area' => $area,
            'standbyCount' => $standby,
            'todaysCasts' => $todaysCasts,
            'balance' => $this->availablePoints(),
        ]);
    }

    /** 条件入力フォーム。 */
    public function create(): View
    {
        return view('calls.create', [
            'areas' => Area::where('serviceable', true)->get(),
            'balance' => $this->availablePoints(),
        ]);
    }

    /** 確認画面（見積を提示。初回は注意喚起を表示）。 */
    public function confirm(CreateCallRequest $request): View
    {
        $data = $request->validated();
        $quote = $this->service->preview(
            (int) $data['area_id'],
            $request->lineItems(),
            (int) $data['duration_min'],
            (bool) ($data['is_night'] ?? false),
        );

        $firstCall = ! Call::where('guest_user_id', Auth::id())->exists();

        return view('calls.confirm', [
            'input' => $data,
            'items' => $request->lineItems(),
            'quote' => $quote,
            'area' => Area::find($data['area_id']),
            'firstCall' => $firstCall,
            'balance' => $this->availablePoints(),
        ]);
    }

    /** 作成して与信ホールド。成立待ちへ。 */
    public function store(CreateCallRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $startAt = now()->addMinutes((int) $data['start_offset_min']);

        try {
            $result = $this->service->create(
                guestUserId: (int) Auth::id(),
                areaId: (int) $data['area_id'],
                lineItems: $request->lineItems(),
                startAt: $startAt->toDateTimeString(),
                durationMin: (int) $data['duration_min'],
                isNight: (bool) ($data['is_night'] ?? false),
                venueKind: $data['venue_kind'],
                note: $data['note'] ?? null,
                nominatedCastProfileId: $data['nominated_cast_profile_id'] ?? null,
            );
        } catch (\DomainException $e) {
            $message = str_starts_with($e->getMessage(), 'insufficient_points')
                ? 'ポイント残高が不足しています。チャージしてからお試しください。'
                : 'ご利用条件を満たしていません（本人確認・対応エリアをご確認ください）。';

            return back()->withInput()->with('error', $message);
        }

        return redirect()
            ->route('calls.show', $result['call'])
            ->with('status', '呼び出しを作成しました。キャストが応じるまでお待ちください。');
    }

    /** 呼び出し詳細（成立待ち / 成立 / 合流中 / 完了）。 */
    public function show(Call $call): View
    {
        abort_unless($call->guest_user_id === Auth::id(), 403);

        return view('calls.show', [
            'call' => $call->load('area', 'lineItems.classTier', 'participants.castProfile.classTier'),
            'balance' => $this->availablePoints(),
            'minTip' => CallLifecycleService::MIN_TIP_POINTS,
            'reviewedUserIds' => Review::where('call_id', $call->id)
                ->where('rater_user_id', Auth::id())
                ->pluck('ratee_user_id')->all(),
            'reviewTags' => ReviewService::GUEST_TAGS,
        ]);
    }

    /** 注文履歴。 */
    public function index(): View
    {
        return view('calls.index', [
            'calls' => Call::where('guest_user_id', Auth::id())
                ->with('area')->latest()->paginate(20),
            'balance' => $this->availablePoints(),
        ]);
    }

    /** 合流開始（matched → in_progress）。 */
    public function start(Call $call, CallLifecycleService $lifecycle): RedirectResponse
    {
        abort_unless($call->guest_user_id === Auth::id(), 403);

        return $this->run(fn () => $lifecycle->start($call), $call, '合流を開始しました。');
    }

    /** 完了（確定消費＋キャスト報酬の計上）。 */
    public function complete(Call $call, CallLifecycleService $lifecycle): RedirectResponse
    {
        abort_unless($call->guest_user_id === Auth::id(), 403);

        return $this->run(
            fn () => $lifecycle->complete($call),
            $call,
            'ご利用ありがとうございました。お支払いが確定しました。',
        );
    }

    /** キャンセル（与信を解放）。 */
    public function cancel(Call $call, CallLifecycleService $lifecycle): RedirectResponse
    {
        abort_unless($call->guest_user_id === Auth::id(), 403);

        return $this->run(
            fn () => $lifecycle->release($call),
            $call,
            'キャンセルしました。与信していたポイントは戻ります。',
        );
    }

    /** おひねり。 */
    public function tip(Request $request, Call $call, CallLifecycleService $lifecycle): RedirectResponse
    {
        abort_unless($call->guest_user_id === Auth::id(), 403);

        $validated = $request->validate([
            'points' => ['required', 'integer', 'min:'.CallLifecycleService::MIN_TIP_POINTS],
        ]);

        return $this->run(
            fn () => $lifecycle->tip($call, (int) $validated['points']),
            $call,
            'おひねりを送りました。',
        );
    }

    /** 延長（30分単位。追加ぶんを与信）。 */
    public function extend(Request $request, Call $call, CallLifecycleService $lifecycle): RedirectResponse
    {
        abort_unless($call->guest_user_id === Auth::id(), 403);

        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'in:30,60,90,120'],
        ]);

        return $this->run(
            fn () => $lifecycle->extend($call, (int) $validated['minutes']),
            $call,
            $validated['minutes'].'分延長しました。',
        );
    }

    /** レビュー投稿（完了後）。 */
    public function review(Request $request, Call $call, ReviewService $reviews): RedirectResponse
    {
        abort_unless($call->guest_user_id === Auth::id(), 403);

        $validated = $request->validate([
            'ratee_user_id' => ['required', 'integer', 'exists:users,id'],
            'stars' => ['required', 'integer', 'min:1', 'max:5'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', Rule::in(ReviewService::GUEST_TAGS)],
            'comment' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $reviews->post(
                $call,
                Auth::user(),
                User::findOrFail($validated['ratee_user_id']),
                (int) $validated['stars'],
                $validated['tags'] ?? [],
                $validated['comment'] ?? null,
            );
        } catch (\DomainException $e) {
            return redirect()->route('calls.show', $call)->with('error', match ($e->getMessage()) {
                'already_reviewed' => 'すでに評価済みです。',
                'call_not_completed' => '完了後に評価できます。',
                'not_a_participant' => 'この呼び出しの参加者ではありません。',
                default => '評価を送信できませんでした。',
            });
        }

        return redirect()->route('calls.show', $call)->with('status', '評価を送信しました。');
    }

    /** ライフサイクル操作の共通ハンドリング（ドメイン例外を日本語に変換）。 */
    private function run(callable $action, Call $call, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return redirect()->route('calls.show', $call)
                ->with('error', $this->humanize($e->getMessage()));
        }

        return redirect()->route('calls.show', $call)->with('status', $success);
    }

    private function humanize(string $code): string
    {
        return match (true) {
            str_contains($code, 'insufficient_points_for_tip') => 'ポイント残高が不足しています。',
            str_contains($code, 'tip_below_minimum') => 'おひねりは'.number_format(CallLifecycleService::MIN_TIP_POINTS).'P以上で指定してください。',
            str_contains($code, 'no_participants') => '参加キャストがいません。',
            str_contains($code, '不正な状態遷移') => 'この操作は現在の状態では実行できません。',
            default => '操作を完了できませんでした。',
        };
    }

    private function availablePoints(): int
    {
        $wallet = PointWallet::where('user_id', Auth::id())->first();
        if ($wallet === null) {
            return 0;
        }

        return app(WalletRepository::class)
            ->load($wallet->id)->balance()->available();
    }
}
