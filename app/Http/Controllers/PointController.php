<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Point\PurchasePointService;
use App\Domain\Point\Contracts\WalletRepository;
use App\Models\PointProduct;
use App\Models\PointTransaction;
use App\Models\PointWallet;
use App\Support\Contracts\ChargeResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * ポイントの購入と履歴。
 * 1有償ポイント=¥1.2 相当、払戻し・換金不可、有効期限は付与から180日（docs/04・06）。
 *
 * カード番号はこのアプリを一切通らない。ブラウザの Stripe.js が直接 Stripe へ送り、
 * ここへ来るのは PaymentMethod のトークンだけ（docs/08 §1）。
 */
final class PointController extends Controller
{
    /** 3Dセキュア待ちの決済をセッションに預けるときの鍵 */
    private const PENDING_KEY = 'points.pending_charge';

    public function index(): View
    {
        $wallet = PointWallet::firstOrCreate(['user_id' => Auth::id()]);

        return view('points.index', [
            'products' => PointProduct::where('active', true)->orderBy('paid_points')->get(),
            'balance' => app(WalletRepository::class)->load($wallet->id)->balance()->available(),
            'history' => PointTransaction::where('wallet_id', $wallet->id)->latest('id')->limit(20)->get(),
            'expiryDays' => PurchasePointService::EXPIRY_DAYS,
            'publishableKey' => (string) config('services.stripe.publishable_key', ''),
        ]);
    }

    public function purchase(Request $request, PurchasePointService $service): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:point_products,id'],
            // Stripe.js が発行した PaymentMethod ID。カード番号ではない
            'payment_method_token' => ['nullable', 'string', 'max:255'],
        ]);

        $product = PointProduct::findOrFail($validated['product_id']);

        try {
            $result = $service->purchase(
                (int) Auth::id(),
                $product,
                $validated['payment_method_token'] ?? 'demo-token',
            );
        } catch (\DomainException) {
            return back()->with('error', 'この商品は現在購入できません。');
        } catch (\RuntimeException) {
            return back()->with('error', '決済に失敗しました。カード情報をご確認ください。');
        }

        if ($result['status'] === ChargeResult::REQUIRES_ACTION) {
            // 誰のどの購入かをサーバ側に預ける。確定時にクライアントの申告を信用しないため
            $request->session()->put(self::PENDING_KEY, [
                'charge_ref' => $result['charge_ref'],
                'product_id' => $product->id,
            ]);

            return back()->with('stripe_action', [
                'client_secret' => $result['client_secret'],
            ]);
        }

        return $this->done($result);
    }

    /**
     * 3Dセキュア認証を終えたブラウザからの確定要求。
     *
     * クライアントは決済IDすら送ってこない（送らせない）。**セッションに預けた
     * 決済IDと商品でのみ確定する**ため、他人の決済や別商品への差し替えができない。
     */
    public function confirm(Request $request, PurchasePointService $service): RedirectResponse
    {
        $pending = $request->session()->pull(self::PENDING_KEY);

        if (! is_array($pending) || ! isset($pending['charge_ref'], $pending['product_id'])) {
            return redirect()->route('points.index')->with('error', '購入手続きの有効期限が切れました。最初からやり直してください。');
        }

        $product = PointProduct::find($pending['product_id']);
        if ($product === null) {
            return redirect()->route('points.index')->with('error', 'この商品は現在購入できません。');
        }

        try {
            $result = $service->finalize((int) Auth::id(), $product, (string) $pending['charge_ref']);
        } catch (\RuntimeException) {
            return redirect()->route('points.index')->with('error', '決済を確認できませんでした。反映されない場合はお問い合わせください。');
        }

        return $this->done($result);
    }

    /** @param array{points:int, yen:int} $result */
    private function done(array $result): RedirectResponse
    {
        return redirect()->route('points.index')->with(
            'status',
            number_format($result['points']).'Pをチャージしました（¥'.number_format($result['yen']).'）。',
        );
    }
}
