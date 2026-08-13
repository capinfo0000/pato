<?php

declare(strict_types=1);

namespace App\Application\Point;

use App\Domain\Point\Contracts\WalletRepository;
use App\Domain\Point\Enums\PointKind;
use App\Domain\Point\Enums\TransactionType;
use App\Domain\Point\Support\PointTransaction as PointTx;
use App\Models\PointPurchase;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 返金・チャージバックに伴うポイントの回収。
 *
 * 返金したのにポイントが残ると、そのぶんが丸ごと損失になる。ここは必ず動くこと。
 *
 * 回収の考え方:
 * - 原則、購入したポイント数を戻す（`refund` として台帳に追記）
 * - **部分返金は何度でも起きる**。PSP が渡してくる返金額は累計なので、累計に対応する
 *   ポイント数と回収済みの差分だけを追加で回収する（「返金済みなら何もしない」にすると
 *   2回目以降の分割返金を取りこぼす）
 * - すでに使われていて残高が足りない場合も**回収は行う**。残高がマイナスになるのを
 *   許容し、運営が検知して対応する（黙って取りこぼすより、負債として見える方が良い）
 * - 残高不足は監査ログとエラーログの両方に残す
 */
final class RefundPointService
{
    public function __construct(private readonly WalletRepository $wallets) {}

    /**
     * 決済参照ID（Stripe の PaymentIntent / Charge）に対応する購入を返金扱いにする。
     *
     * @param  int|null  $refundedYen  部分返金の金額。null なら全額
     * @return bool 回収を行ったか（対象なし・処理済みなら false）
     */
    public function refundByChargeRef(string $chargeRef, ?int $refundedYen = null): bool
    {
        return DB::transaction(function () use ($chargeRef, $refundedYen) {
            $purchase = PointPurchase::where('charge_ref', $chargeRef)->lockForUpdate()->first();

            if ($purchase === null) {
                Log::warning('refund.purchase_not_found', ['charge_ref' => $chargeRef]);

                return false;
            }
            // Stripe の amount_refunded は「これまでの累計」。累計に対応するポイント数を
            // 出し、すでに回収済みとの差分だけを追加で回収する。
            // 「返金済みなら何もしない」にすると、¥1000 → ¥1000 と分割返金されたときに
            // 2回目を取りこぼす（部分返金は何度でも起きる）。
            $target = $refundedYen === null || $refundedYen >= $purchase->price_yen
                ? $purchase->paid_points
                // 端数は切り上げて多めに回収＝取りこぼさない
                : (int) ceil($purchase->paid_points * $refundedYen / $purchase->price_yen);

            $pointsToClaw = $target - $purchase->refunded_points;

            if ($pointsToClaw <= 0) {
                // Webhook の再送、または回収済みの範囲内。何もしない
                return false;
            }

            $balanceBefore = $this->wallets->loadForUpdate($purchase->wallet_id)->balance()->settled();

            $this->wallets->append(
                $purchase->wallet_id,
                new PointTx(TransactionType::Refund, PointKind::Paid, $pointsToClaw),
                // 累計値を鍵に含める。同じ累計の通知が二度来ても DB が弾く
                "refund-{$chargeRef}-{$target}",
            );

            $purchase->update([
                // 最初に返金が起きた時刻を残す（分割返金でも上書きしない）
                'refunded_at' => $purchase->refunded_at ?? now(),
                'refunded_points' => $target,
            ]);

            $shortfall = max(0, $pointsToClaw - $balanceBefore);
            if ($shortfall > 0) {
                // すでに使われていた分。残高はマイナスになる。運営が回収方法を判断する
                Log::error('refund.balance_shortfall', [
                    'charge_ref' => $chargeRef,
                    'wallet_id' => $purchase->wallet_id,
                    'shortfall' => $shortfall,
                ]);
            }

            Audit::log('point.refunded', $purchase, [
                'charge_ref' => $chargeRef,
                'points' => $pointsToClaw,
                'shortfall' => $shortfall,
            ]);

            return true;
        });
    }
}
