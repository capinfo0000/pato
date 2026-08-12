<?php

declare(strict_types=1);

namespace App\Application\Call;

use App\Domain\Call\Call;
use App\Domain\Call\Support\TipDistributor;
use App\Domain\Pricing\DTO\PriceQuote;
use App\Domain\Point\Support\PointTransaction;
use App\Domain\Point\Support\Wallet;

/**
 * 呼び出しの与信・確定・解放・おひねりを、ウォレット台帳と接続するアプリケーションサービス。
 *
 * ここが「状態遷移 × ポイント台帳」の単一の整合点。Laravel 導入後は DBトランザクション境界に置く。
 */
final class CallBillingService
{
    public function __construct(
        private readonly TipDistributor $tipDistributor = new TipDistributor(),
    ) {
    }

    /**
     * 呼び出し作成: 利用可能残高を確認し、見積額を与信（ホールド）して open にする。
     *
     * @throws \DomainException 残高不足
     */
    public function createAndHold(Call $call, Wallet $guestWallet, PriceQuote $quote): void
    {
        if (! $guestWallet->balance()->canHold($quote->guestHoldPoints)) {
            throw new \DomainException('insufficient_points');
        }

        $guestWallet->append(PointTransaction::hold($quote->guestHoldPoints, $call->id));
        $call->open();
    }

    /**
     * 完了: ホールドを確定消費し、参加キャストへ報酬を配分計上する。
     *
     * @param  array<int, Wallet> $castWallets castProfileId => キャストのウォレット
     * @return array<int, int>    castProfileId => 計上した報酬ポイント
     */
    public function complete(Call $call, Wallet $guestWallet, PriceQuote $quote, array $castWallets): array
    {
        $call->complete();

        $guestWallet->append(PointTransaction::capture($quote->guestHoldPoints, $call->id));

        $participants = $call->participantCastIds();
        $payouts = $this->splitEvenly($quote->castPayoutPoints, $participants);

        foreach ($payouts as $castId => $points) {
            $wallet = $castWallets[$castId] ?? throw new \InvalidArgumentException("キャストのウォレット未指定: {$castId}");
            $wallet->append(new PointTransaction(
                \App\Domain\Point\Enums\TransactionType::Grant,
                \App\Domain\Point\Enums\PointKind::Paid,
                $points,
                $call->id,
            ));
        }

        return $payouts;
    }

    /**
     * キャンセル/不成立: ホールドを解放して残高を戻す。
     */
    public function release(Call $call, Wallet $guestWallet, PriceQuote $quote, bool $expired = false): void
    {
        $expired ? $call->expire() : $call->cancel();
        $guestWallet->append(PointTransaction::release($quote->guestHoldPoints, $call->id));
    }

    /**
     * おひねり: 総額を配分し、ゲストから消費、各キャストへ付与する。
     *
     * @param  array<int, Wallet>   $castWallets castProfileId => ウォレット
     * @param  array<int, int>|null $explicit    指定配分（任意）
     * @return array<int, int>      castProfileId => 付与ポイント
     */
    public function applyTip(Call $call, Wallet $guestWallet, array $castWallets, int $totalPoints, ?array $explicit = null): array
    {
        if (! $guestWallet->balance()->canHold($totalPoints)) {
            throw new \DomainException('insufficient_points_for_tip');
        }

        $distribution = $this->tipDistributor->distribute($totalPoints, $call->participantCastIds(), $explicit);

        $guestWallet->append(PointTransaction::tip($totalPoints, $call->id));
        foreach ($distribution as $castId => $points) {
            if ($points === 0) {
                continue;
            }
            $wallet = $castWallets[$castId] ?? throw new \InvalidArgumentException("キャストのウォレット未指定: {$castId}");
            $wallet->append(new PointTransaction(
                \App\Domain\Point\Enums\TransactionType::Grant,
                \App\Domain\Point\Enums\PointKind::Paid,
                $points,
                $call->id,
            ));
        }

        return $distribution;
    }

    /**
     * キャスト報酬を参加人数で均等配分（端数は先頭へ寄せる）。
     *
     * @param  list<int>       $castIds
     * @return array<int, int>
     */
    private function splitEvenly(int $total, array $castIds): array
    {
        return $this->tipDistributor->distribute($total, $castIds);
    }
}
