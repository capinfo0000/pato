<?php

declare(strict_types=1);

namespace App\Application\Call;

use App\Domain\Point\Contracts\WalletRepository;
use App\Domain\Point\Support\PointTransaction as PointTx;
use App\Domain\Pricing\Contracts\PriceTableRepository;
use App\Domain\Pricing\DTO\CallLineItem as LineItemDTO;
use App\Domain\Pricing\DTO\PriceQuote;
use App\Domain\Pricing\Enums\CastClass;
use App\Domain\Pricing\PricingCalculator;
use App\Domain\Trust\AccessGate;
use App\Models\Area;
use App\Models\Call;
use App\Models\ClassTier;
use App\Models\IdentityVerification;
use App\Models\PointWallet;
use Illuminate\Support\Facades\DB;

/**
 * patoコールの作成ユースケース。
 *
 * ゲート（年齢/本人確認/エリア）→ 料金見積 → 残高確認 → DBトランザクションで
 * Call(open) 生成・明細保存・与信ホールドを一貫して行う。ここが唯一の作成経路。
 */
final class CreateCallService
{
    public function __construct(
        private readonly PriceTableRepository $priceTable,
        private readonly WalletRepository $wallets,
        private readonly PricingCalculator $calculator = new PricingCalculator,
    ) {}

    /**
     * 確定せずに見積だけ返す（確認画面用）。
     *
     * @param  list<array{class:string,headcount:int,nominated?:bool}>  $lineItems
     */
    public function preview(int $areaId, array $lineItems, int $durationMin, bool $isNight): PriceQuote
    {
        $table = $this->priceTable->forArea($areaId);

        return $this->calculator->quote($table, $this->toDtos($lineItems), $durationMin, $isNight);
    }

    /**
     * 呼び出しを作成して与信ホールドする。
     *
     * @param  list<array{class:string,headcount:int,nominated?:bool}>  $lineItems
     * @return array{call: Call, quote: PriceQuote}
     *
     * @throws \DomainException ゲート不許可 / 残高不足
     */
    public function create(
        int $guestUserId,
        int $areaId,
        array $lineItems,
        string $startAt,
        int $durationMin,
        bool $isNight,
        string $venueKind,
        ?string $note = null,
        ?int $nominatedCastProfileId = null,
    ): array {
        $this->assertGate($guestUserId, $areaId);

        $quote = $this->preview($areaId, $lineItems, $durationMin, $isNight);
        $dtos = $this->toDtos($lineItems);
        $headcount = array_sum(array_map(static fn (LineItemDTO $d) => $d->headcount, $dtos));
        $isMix = count($dtos) > 1;

        return DB::transaction(function () use (
            $guestUserId, $areaId, $lineItems, $startAt, $durationMin,
            $isNight, $venueKind, $note, $quote, $headcount, $isMix, $nominatedCastProfileId
        ) {
            $wallet = PointWallet::firstOrCreate(['user_id' => $guestUserId]);

            if (! $this->wallets->load($wallet->id)->balance()->canHold($quote->guestHoldPoints)) {
                throw new \DomainException('insufficient_points');
            }

            $call = Call::create([
                'guest_user_id' => $guestUserId,
                'area_id' => $areaId,
                'start_at' => $startAt,
                'duration_min' => $durationMin,
                'headcount' => $headcount,
                'hold_points' => $quote->guestHoldPoints,
                'cast_payout_points' => $quote->castPayoutPoints,
                'status' => 'open',
                'is_mix' => $isMix,
                'is_night' => $isNight,
                'venue_kind' => $venueKind,
                'nominated_cast_profile_id' => $nominatedCastProfileId,
                'note' => $note,
            ]);

            $tiers = ClassTier::pluck('id', 'code');
            foreach ($lineItems as $item) {
                $call->lineItems()->create([
                    'class_tier_id' => $tiers[$item['class']],
                    'headcount' => $item['headcount'],
                    'nominated' => $item['nominated'] ?? false,
                ]);
            }

            $this->wallets->append(
                $wallet->id,
                PointTx::hold($quote->guestHoldPoints, $call->id),
                "hold-call-{$call->id}",
            );

            return ['call' => $call, 'quote' => $quote];
        });
    }

    private function assertGate(int $guestUserId, int $areaId): void
    {
        $verification = IdentityVerification::where('user_id', $guestUserId)
            ->where('status', 'verified')
            ->latest('verified_at')
            ->first();

        $area = Area::find($areaId);

        $gate = new AccessGate(
            identityVerified: $verification !== null,
            isAdult: (bool) ($verification?->is_adult),
            areaServiceable: (bool) ($area?->serviceable),
        );

        if (! $gate->canCreateCall()) {
            throw new \DomainException('gate_denied: '.implode(',', $gate->reasons()));
        }
    }

    /**
     * @param  list<array{class:string,headcount:int,nominated?:bool}>  $lineItems
     * @return list<LineItemDTO>
     */
    private function toDtos(array $lineItems): array
    {
        return array_map(
            static fn (array $i) => new LineItemDTO(
                CastClass::from($i['class']),
                $i['headcount'],
                $i['nominated'] ?? false,
            ),
            $lineItems,
        );
    }
}
