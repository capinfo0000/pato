<?php

declare(strict_types=1);

namespace App\Application\Payout;

use App\Domain\Point\Contracts\WalletRepository;
use App\Domain\Point\Enums\PointKind;
use App\Domain\Point\Enums\TransactionType;
use App\Domain\Point\Support\PointTransaction as PointTx;
use App\Models\CallParticipant;
use App\Models\CastProfile;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\PointWallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * キャスト精算。完了した呼び出しの報酬（payout_items）を集約して出金申請 → 承認 → 送金。
 *
 * ポイント台帳との関係:
 * - 完了時に Grant で受取ポイントが増える（CallLifecycleService）
 * - 送金完了（paid）時に payout_debit を計上して残高を落とす
 *   → 「残高＝まだ出金していない受取分」となり、二重支払いを防ぐ
 *
 * 参考（pato）: 通常は翌月末払い、早期振込オプションあり。手数料・下限額は要ビジネス判断。
 */
final class PayoutService
{
    /** 出金申請の下限（ポイント）。 */
    public const MIN_REQUEST_POINTS = 3000;

    /** 早期振込の手数料（ポイント）。 */
    public const EXPRESS_FEE_POINTS = 500;

    public function __construct(private readonly WalletRepository $wallets) {}

    /** 未申請の報酬（payout_items のうち payout_id が null）の合計。 */
    public function pendingPoints(CastProfile $cast): int
    {
        return (int) PayoutItem::whereNull('payout_id')
            ->whereIn('call_participant_id', $this->participantIds($cast))
            ->sum('amount_points');
    }

    /**
     * 出金申請。未申請の報酬をまとめて1件の Payout にする。
     */
    public function request(CastProfile $cast, string $speed = 'normal'): Payout
    {
        if (! in_array($speed, ['normal', 'express'], true)) {
            throw new \InvalidArgumentException('speed は normal / express');
        }

        return DB::transaction(function () use ($cast, $speed) {
            $items = PayoutItem::whereNull('payout_id')
                ->whereIn('call_participant_id', $this->participantIds($cast))
                ->lockForUpdate()
                ->get();

            $total = (int) $items->sum('amount_points');

            if ($total < self::MIN_REQUEST_POINTS) {
                throw new \DomainException('below_minimum');
            }

            $wallet = PointWallet::firstOrCreate(['user_id' => $cast->user_id]);

            // 早期振込は手数料を差し引く
            $fee = $speed === 'express' ? self::EXPRESS_FEE_POINTS : 0;

            $payout = Payout::create([
                'cast_wallet_id' => $wallet->id,
                'amount_points' => $total - $fee,
                'status' => 'requested',
                'speed' => $speed,
                'requested_at' => now(),
            ]);

            PayoutItem::whereIn('id', $items->pluck('id'))->update(['payout_id' => $payout->id]);

            return $payout;
        });
    }

    /** 管理者による承認。 */
    public function approve(Payout $payout, User $admin): Payout
    {
        return DB::transaction(function () use ($payout) {
            $payout->refresh();
            if ($payout->status !== 'requested') {
                throw new \DomainException('not_requested');
            }

            $payout->update(['status' => 'approved']);

            return $payout->fresh();
        });
    }

    /**
     * 送金完了。台帳へ payout_debit を計上して受取残高を落とす。
     * 実際の振込は外部送金（銀行API等）で行い、その完了を受けて呼ぶ。
     */
    public function markPaid(Payout $payout): Payout
    {
        return DB::transaction(function () use ($payout) {
            $payout->refresh();
            if ($payout->status !== 'approved') {
                throw new \DomainException('not_approved');
            }

            $this->wallets->append(
                $payout->cast_wallet_id,
                new PointTx(TransactionType::PayoutDebit, PointKind::Paid, $payout->amount_points),
                "payout-{$payout->id}",
            );

            $payout->update(['status' => 'paid', 'paid_at' => now()]);

            return $payout->fresh();
        });
    }

    /** 却下（申請を差し戻し、明細を未申請に戻す）。 */
    public function reject(Payout $payout): Payout
    {
        return DB::transaction(function () use ($payout) {
            $payout->refresh();
            if (! in_array($payout->status, ['requested', 'approved'], true)) {
                throw new \DomainException('cannot_reject');
            }

            PayoutItem::where('payout_id', $payout->id)->update(['payout_id' => null]);
            $payout->update(['status' => 'rejected']);

            return $payout->fresh();
        });
    }

    /** @return list<int> */
    private function participantIds(CastProfile $cast): array
    {
        return CallParticipant::where('cast_profile_id', $cast->id)->pluck('id')->all();
    }
}
