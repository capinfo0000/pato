<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cast;

use App\Application\Payout\PayoutService;
use App\Domain\Point\Contracts\WalletRepository;
use App\Http\Controllers\Controller;
use App\Models\CastProfile;
use App\Models\Payout;
use App\Models\PointWallet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** キャストの精算（受取確認と出金申請）。 */
final class PayoutController extends Controller
{
    public function index(PayoutService $service): View
    {
        $cast = $this->cast();
        $wallet = PointWallet::firstOrCreate(['user_id' => Auth::id()]);

        return view('cast.payouts', [
            'cast' => $cast,
            'pending' => $service->pendingPoints($cast),
            'payouts' => Payout::where('cast_wallet_id', $wallet->id)->latest()->get(),
            'balance' => app(WalletRepository::class)->load($wallet->id)->balance()->available(),
            'minPoints' => PayoutService::MIN_REQUEST_POINTS,
            'expressFee' => PayoutService::EXPRESS_FEE_POINTS,
        ]);
    }

    public function request(Request $request, PayoutService $service): RedirectResponse
    {
        $validated = $request->validate([
            'speed' => ['required', Rule::in(['normal', 'express'])],
        ]);

        try {
            $service->request($this->cast(), $validated['speed']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage() === 'below_minimum'
                ? '出金申請は'.number_format(PayoutService::MIN_REQUEST_POINTS).'P以上から可能です。'
                : '出金申請できませんでした。');
        }

        return redirect()->route('cast.payouts')->with('status', '出金を申請しました。');
    }

    private function cast(): CastProfile
    {
        return CastProfile::where('user_id', Auth::id())->firstOrFail();
    }
}
