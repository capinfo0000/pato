<?php

declare(strict_types=1);

namespace App\Application\Call;

use App\Application\Messaging\PostMessageService;
use App\Application\Ranking\RankingService;
use App\Domain\Call\CallStateMachine;
use App\Domain\Call\Enums\CallStatus;
use App\Domain\Call\Support\TipDistributor;
use App\Domain\Point\Contracts\WalletRepository;
use App\Domain\Point\Enums\PointKind;
use App\Domain\Point\Enums\TransactionType;
use App\Domain\Point\Support\PointTransaction as PointTx;
use App\Domain\Pricing\Contracts\PriceTableRepository;
use App\Domain\Pricing\DTO\CallLineItem as LineItemDTO;
use App\Domain\Pricing\DTO\PriceQuote;
use App\Domain\Pricing\Enums\CastClass;
use App\Domain\Pricing\PricingCalculator;
use App\Models\Call;
use App\Models\CastProfile;
use App\Models\PayoutItem;
use App\Models\PointWallet;
use App\Support\Contracts\PushSender;
use Illuminate\Support\Facades\DB;

/**
 * 呼び出しの成立以降のライフサイクル（参加表明→成立→開始→完了→精算 / キャンセル / おひねり）。
 *
 * 状態遷移は CallStateMachine を通し、ポイントの移動は台帳（point_transactions）へ追記する。
 * すべて DBトランザクション境界の中で行う。
 */
final class CallLifecycleService
{
    public function __construct(
        private readonly WalletRepository $wallets,
        private readonly PushSender $push,
        private readonly PriceTableRepository $prices,
        private readonly RankingService $rankings = new RankingService,
        private readonly PostMessageService $messages = new PostMessageService,
        private readonly CallStateMachine $sm = new CallStateMachine,
        private readonly TipDistributor $tips = new TipDistributor,
        private readonly PricingCalculator $calculator = new PricingCalculator,
    ) {}

    /**
     * キャストの参加表明。定員に達したら成立（matched）させる。
     *
     * @return bool 成立したか
     */
    public function participate(Call $call, CastProfile $cast): bool
    {
        return DB::transaction(function () use ($call, $cast) {
            $call->refresh();

            if ($call->status !== CallStatus::Open) {
                throw new \DomainException('call_not_open');
            }
            if (! $cast->is_active || $cast->screening_status !== 'approved') {
                throw new \DomainException('cast_not_approved');
            }
            if ($cast->user?->status !== 'active') {
                throw new \DomainException('cast_suspended');
            }
            if ($cast->in_session) {
                throw new \DomainException('cast_in_session');
            }
            if ($call->participants()->where('cast_profile_id', $cast->id)->exists()) {
                throw new \DomainException('already_participating');
            }

            $call->participants()->create([
                'cast_profile_id' => $cast->id,
                'status' => 'accepted',
            ]);

            $accepted = $call->participants()->count();
            if ($accepted < $call->headcount) {
                return false;
            }

            $this->sm->assertCanTransition($call->status, CallStatus::Matched);
            $call->update(['status' => CallStatus::Matched]);

            // 成立したらゲストと参加キャストのグループチャットを開く
            $this->messages->ensureCallThread($call->fresh());

            $this->push->send(
                $call->guest_user_id,
                'キャストが決まりました',
                '集合場所と時間をご確認ください。',
                ['call_id' => $call->id],
            );

            return true;
        });
    }

    /** 合流開始（matched → in_progress）。参加キャストを「合流中」にする。 */
    public function start(Call $call): void
    {
        DB::transaction(function () use ($call) {
            $call->refresh();
            $this->sm->assertCanTransition($call->status, CallStatus::InProgress);

            $call->update(['status' => CallStatus::InProgress]);
            $call->participants()->update(['status' => 'joined', 'joined_at' => now()]);

            CastProfile::whereIn('id', $call->participants()->pluck('cast_profile_id'))
                ->update(['in_session' => true]);
        });
    }

    /**
     * 延長（30分単位）。追加ぶんを与信し、完了時にまとめて確定消費する。
     * 合流中（in_progress）のみ可能。
     *
     * @return array{added_hold:int, added_payout:int}
     */
    public function extend(Call $call, int $minutes): array
    {
        if ($minutes <= 0 || $minutes % 30 !== 0) {
            throw new \InvalidArgumentException('延長は30分単位');
        }

        return DB::transaction(function () use ($call, $minutes) {
            $call->refresh();

            if ($call->status !== CallStatus::InProgress) {
                throw new \DomainException('not_in_progress');
            }

            // 現在の明細と同条件で、追加時間ぶんの見積を取る
            $quote = $this->quoteForExtension($call, $minutes);

            $wallet = PointWallet::firstOrCreate(['user_id' => $call->guest_user_id]);
            // ロックを取ってから残高を見る（同時実行で二重に与信されるのを防ぐ）
            if (! $this->wallets->loadForUpdate($wallet->id)->balance()->canHold($quote->guestHoldPoints)) {
                throw new \DomainException('insufficient_points_for_extension');
            }

            $seq = $call->duration_min; // 延長ごとに冪等キーを分ける
            $this->wallets->append(
                $wallet->id,
                PointTx::hold($quote->guestHoldPoints, $call->id),
                "extend-call-{$call->id}-{$seq}",
            );

            $call->update([
                'duration_min' => $call->duration_min + $minutes,
                'hold_points' => $call->hold_points + $quote->guestHoldPoints,
                'cast_payout_points' => $call->cast_payout_points + $quote->castPayoutPoints,
            ]);

            return [
                'added_hold' => $quote->guestHoldPoints,
                'added_payout' => $quote->castPayoutPoints,
            ];
        });
    }

    /** 延長ぶんの料金を、元の呼び出しと同じ条件で見積もる。 */
    private function quoteForExtension(Call $call, int $minutes): PriceQuote
    {
        $table = $this->prices->forArea((int) $call->area_id);

        $items = $call->lineItems()->with('classTier')->get()->map(
            fn ($li) => new LineItemDTO(
                CastClass::from($li->classTier->code),
                $li->headcount,
                (bool) $li->nominated,
            ),
        )->all();

        return $this->calculator->quote($table, $items, $minutes, (bool) $call->is_night);
    }

    /**
     * 完了（in_progress → completed）。
     * ゲストの与信を確定消費し、キャスト報酬を配分して台帳と精算明細に計上する。
     *
     * @return array<int,int> castProfileId => 報酬ポイント
     */
    public function complete(Call $call): array
    {
        return DB::transaction(function () use ($call) {
            $call->refresh();
            $this->sm->assertCanTransition($call->status, CallStatus::Completed);

            $participants = $call->participants()->get();
            if ($participants->isEmpty()) {
                throw new \DomainException('no_participants');
            }

            // ゲスト: hold → capture（確定消費）
            $guestWallet = PointWallet::firstOrCreate(['user_id' => $call->guest_user_id]);
            $this->wallets->append(
                $guestWallet->id,
                PointTx::capture($call->hold_points, $call->id),
                "capture-call-{$call->id}",
            );

            // キャスト: 報酬を均等配分して付与＋精算明細を計上
            $castIds = $participants->pluck('cast_profile_id')->all();
            $distribution = $this->tips->distribute($call->cast_payout_points, $castIds);

            foreach ($participants as $participant) {
                $points = $distribution[$participant->cast_profile_id];
                $castUserId = CastProfile::find($participant->cast_profile_id)->user_id;
                $castWallet = PointWallet::firstOrCreate(['user_id' => $castUserId]);

                $this->wallets->append(
                    $castWallet->id,
                    new PointTx(TransactionType::Grant, PointKind::Paid, $points, $call->id),
                    "payout-call-{$call->id}-cast-{$participant->cast_profile_id}",
                );

                PayoutItem::create([
                    'call_participant_id' => $participant->id,
                    'amount_points' => $points,
                ]);

                // ファンポイント（キャストの評価スコア）を加算
                $this->rankings->awardFanPoints($call, $participant->cast_profile_id, $points);

                $participant->update(['status' => 'completed']);
            }

            $call->update(['status' => CallStatus::Completed]);
            CastProfile::whereIn('id', $castIds)->update(['in_session' => false]);

            return $distribution;
        });
    }

    /** キャンセル / 不成立。与信を解放して残高を戻す。 */
    public function release(Call $call, bool $expired = false): void
    {
        DB::transaction(function () use ($call, $expired) {
            $call->refresh();
            $to = $expired ? CallStatus::Expired : CallStatus::Canceled;
            $this->sm->assertCanTransition($call->status, $to);

            $wallet = PointWallet::firstOrCreate(['user_id' => $call->guest_user_id]);
            $this->wallets->append(
                $wallet->id,
                PointTx::release($call->hold_points, $call->id),
                "release-call-{$call->id}",
            );

            $call->update(['status' => $to]);
            $call->participants()->update(['status' => 'canceled']);
            CastProfile::whereIn('id', $call->participants()->pluck('cast_profile_id'))
                ->update(['in_session' => false]);
        });
    }

    /**
     * おひねり。ゲストから消費し、参加キャストへ配分して付与する。
     *
     * @param  array<int,int>|null  $explicit  castProfileId => points
     * @return array<int,int>
     */
    public function tip(Call $call, int $totalPoints, ?array $explicit = null): array
    {
        if ($totalPoints < self::MIN_TIP_POINTS) {
            throw new \DomainException('tip_below_minimum');
        }

        return DB::transaction(function () use ($call, $totalPoints, $explicit) {
            $call->refresh();

            $participants = $call->participants()->get();
            if ($participants->isEmpty()) {
                throw new \DomainException('no_participants');
            }

            $guestWallet = PointWallet::firstOrCreate(['user_id' => $call->guest_user_id]);
            // ロックを取ってから残高を見る（同時実行で残高以上に送れるのを防ぐ）
            if (! $this->wallets->loadForUpdate($guestWallet->id)->balance()->canHold($totalPoints)) {
                throw new \DomainException('insufficient_points_for_tip');
            }

            $distribution = $this->tips->distribute(
                $totalPoints,
                $participants->pluck('cast_profile_id')->all(),
                $explicit,
            );

            $seq = $call->participants()->sum('tip_points'); // 複数回のおひねりで冪等キーを分ける
            $this->wallets->append(
                $guestWallet->id,
                PointTx::tip($totalPoints, $call->id),
                "tip-call-{$call->id}-{$seq}",
            );

            foreach ($participants as $participant) {
                $points = $distribution[$participant->cast_profile_id];
                if ($points === 0) {
                    continue;
                }
                $castUserId = CastProfile::find($participant->cast_profile_id)->user_id;
                $castWallet = PointWallet::firstOrCreate(['user_id' => $castUserId]);

                $this->wallets->append(
                    $castWallet->id,
                    new PointTx(TransactionType::Grant, PointKind::Paid, $points, $call->id),
                    "tip-call-{$call->id}-{$seq}-cast-{$participant->cast_profile_id}",
                );

                $participant->increment('tip_points', $points);
            }

            return $distribution;
        });
    }

    /** おひねりの最小単位（docs/06 準拠）。 */
    public const MIN_TIP_POINTS = 5000;

    /** 成立待ちのまま開始時刻を過ぎた呼び出しを不成立にする（バッチ/Job 用）。 */
    public function expireStaleCalls(): int
    {
        $stale = Call::where('status', CallStatus::Open)
            ->where('start_at', '<', now())
            ->get();

        foreach ($stale as $call) {
            $this->release($call, expired: true);
        }

        return $stale->count();
    }
}
