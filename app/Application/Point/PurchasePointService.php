<?php

declare(strict_types=1);

namespace App\Application\Point;

use App\Domain\Point\Contracts\WalletRepository;
use App\Domain\Point\Support\PointTransaction as PointTx;
use App\Models\PointProduct;
use App\Models\PointPurchase;
use App\Models\PointWallet;
use App\Support\Contracts\ChargeResult;
use App\Support\Contracts\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ポイント購入。PSP で課金 → 台帳へ有償ポイントを追記する。
 *
 * - カード情報は保持せず、PSP のトークンのみ扱う。
 * - 冪等キーで二重課金・二重計上を防ぐ。台帳側の鍵は**決済参照ID**から作るため、
 *   同じ決済で二度ポイントが増えることはない（3Dセキュアの再送でも安全）。
 * - 3Dセキュアが要求されたら `requires_action` を返し、ブラウザ認証後に `finalize()` で確定する。
 * - 有効期限は付与日から180日（資金決済法の適用除外。docs/04 §4）。
 */
final class PurchasePointService
{
    public const EXPIRY_DAYS = 180;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly WalletRepository $wallets,
    ) {}

    /**
     * @return array{status:string, points:int, yen:int, charge_ref:string, client_secret:?string}
     */
    public function purchase(int $userId, PointProduct $product, string $paymentMethodToken): array
    {
        if (! $product->active) {
            throw new \DomainException('product_inactive');
        }

        $idempotencyKey = sprintf('purchase-%d-%d-%s', $userId, $product->id, now()->format('YmdHis'));

        // PSP 課金は DB トランザクションの外（外部通信をロック内に持ち込まない）
        $charge = $this->gateway->charge($paymentMethodToken, $product->price_yen, $idempotencyKey);

        if ($charge->requiresAction()) {
            // まだ入金していない。ポイントは付与せず、ブラウザでの本人認証を待つ
            return [
                'status' => ChargeResult::REQUIRES_ACTION,
                'points' => $product->paid_points,
                'yen' => $product->price_yen,
                'charge_ref' => $charge->reference,
                'client_secret' => $charge->clientSecret,
            ];
        }

        $this->grant($userId, $product, $charge->reference);

        return [
            'status' => ChargeResult::SUCCEEDED,
            'points' => $product->paid_points,
            'yen' => $product->price_yen,
            'charge_ref' => $charge->reference,
            'client_secret' => null,
        ];
    }

    /**
     * 3Dセキュア認証後の確定。
     *
     * クライアントは「認証が終わった」としか言えない。**状態も金額も PSP に問い合わせて確認する**。
     * これをやらないと、少額の決済IDを使って高額商品のポイントを受け取れてしまう。
     *
     * @return array{status:string, points:int, yen:int, charge_ref:string, client_secret:null}
     */
    public function finalize(int $userId, PointProduct $product, string $chargeRef): array
    {
        $charge = $this->gateway->confirm($chargeRef);

        if (! $charge->succeeded()) {
            throw new \RuntimeException('payment_not_completed');
        }

        // 金額の突き合わせ。ここが決済の最終的な正当性チェックになる
        if ($charge->amountYen !== $product->price_yen) {
            Log::error('purchase.amount_mismatch', [
                'charge_ref' => $chargeRef,
                'user_id' => $userId,
                'expected_yen' => $product->price_yen,
                'actual_yen' => $charge->amountYen,
            ]);

            throw new \RuntimeException('payment_amount_mismatch');
        }

        $this->grant($userId, $product, $charge->reference);

        return [
            'status' => ChargeResult::SUCCEEDED,
            'points' => $product->paid_points,
            'yen' => $product->price_yen,
            'charge_ref' => $charge->reference,
            'client_secret' => null,
        ];
    }

    /**
     * 台帳への付与と購入記録。冪等キーと charge_ref の UNIQUE 制約により、
     * 同じ決済で二度付与されることはない（再送・二重クリックは DB が弾く）。
     */
    private function grant(int $userId, PointProduct $product, string $chargeRef): void
    {
        DB::transaction(function () use ($userId, $product, $chargeRef) {
            $wallet = PointWallet::firstOrCreate(['user_id' => $userId]);

            $this->wallets->append(
                $wallet->id,
                PointTx::purchase(
                    $product->paid_points,
                    now()->addDays(self::EXPIRY_DAYS)->toDateString(),
                ),
                'purchase-'.$chargeRef,
            );

            // 返金時にどの購入を回収するか特定できるよう、決済参照IDと紐づけて残す。
            // これが無いと Stripe の返金通知を受けてもポイントを戻せない（＝損失）
            PointPurchase::create([
                'wallet_id' => $wallet->id,
                'product_id' => $product->id,
                'paid_points' => $product->paid_points,
                'price_yen' => $product->price_yen,
                'charge_ref' => $chargeRef,
            ]);
        });
    }
}
