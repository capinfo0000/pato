<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Call\CreateCallService;
use App\Domain\Point\Contracts\WalletRepository;
use App\Http\Requests\CreateCallRequest;
use App\Models\Area;
use App\Models\Call;
use App\Models\CastProfile;
use App\Models\PointWallet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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

    /** 呼び出し詳細（成立待ち / 成立）。 */
    public function show(Call $call): View
    {
        abort_unless($call->guest_user_id === Auth::id(), 403);

        return view('calls.show', [
            'call' => $call->load('area', 'lineItems.classTier', 'participants'),
            'balance' => $this->availablePoints(),
        ]);
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
