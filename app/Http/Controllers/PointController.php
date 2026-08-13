<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Point\PurchasePointService;
use App\Domain\Point\Contracts\WalletRepository;
use App\Models\PointProduct;
use App\Models\PointTransaction;
use App\Models\PointWallet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * ポイントの購入と履歴。
 * 1有償ポイント=¥1.2 相当、払戻し・換金不可、有効期限は付与から180日（docs/04・06）。
 */
final class PointController extends Controller
{
    public function index(): View
    {
        $wallet = PointWallet::firstOrCreate(['user_id' => Auth::id()]);

        return view('points.index', [
            'products' => PointProduct::where('active', true)->orderBy('paid_points')->get(),
            'balance' => app(WalletRepository::class)->load($wallet->id)->balance()->available(),
            'history' => PointTransaction::where('wallet_id', $wallet->id)->latest('id')->limit(20)->get(),
            'expiryDays' => PurchasePointService::EXPIRY_DAYS,
        ]);
    }

    public function purchase(Request $request, PurchasePointService $service): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:point_products,id'],
            // 実運用では PSP のフロントSDKが発行したトークンを受け取る（カード番号は扱わない）
            'payment_method_token' => ['nullable', 'string'],
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

        return redirect()->route('points.index')->with(
            'status',
            number_format($result['points']).'Pをチャージしました（¥'.number_format($result['yen']).'）。',
        );
    }
}
