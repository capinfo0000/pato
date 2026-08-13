<?php

declare(strict_types=1);

namespace App\Application\Point;

use App\Domain\Point\Contracts\WalletRepository;
use App\Domain\Point\Support\PointTransaction as PointTx;
use App\Models\PointProduct;
use App\Models\PointPurchase;
use App\Models\PointWallet;
use App\Support\Contracts\PaymentGateway;
use Illuminate\Support\Facades\DB;

/**
 * ポイント購入。PSP で課金 → 台帳へ有償ポイントを追記する。
 *
 * - カード情報は保持せず、PSP のトークンのみ扱う。
 * - 冪等キーで二重課金・二重計上を防ぐ。
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
     * @return array{points:int, yen:int, charge_ref:string}
     */
    public function purchase(int $userId, PointProduct $product, string $paymentMethodToken): array
    {
        if (! $product->active) {
            throw new \DomainException('product_inactive');
        }

        $idempotencyKey = sprintf('purchase-%d-%d-%s', $userId, $product->id, now()->format('YmdHis'));

        // PSP 課金は DB トランザクションの外（外部通信をロック内に持ち込まない）
        $chargeRef = $this->gateway->charge($paymentMethodToken, $product->price_yen, $idempotencyKey);

        DB::transaction(function () use ($userId, $product, $idempotencyKey, $chargeRef) {
            $wallet = PointWallet::firstOrCreate(['user_id' => $userId]);

            $this->wallets->append(
                $wallet->id,
                PointTx::purchase(
                    $product->paid_points,
                    now()->addDays(self::EXPIRY_DAYS)->toDateString(),
                ),
                $idempotencyKey,
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

        return [
            'points' => $product->paid_points,
            'yen' => $product->price_yen,
            'charge_ref' => $chargeRef,
        ];
    }
}
