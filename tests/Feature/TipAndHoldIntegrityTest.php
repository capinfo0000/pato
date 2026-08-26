<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Call\CallLifecycleService;
use App\Domain\Point\Contracts\WalletRepository;
use App\Domain\Point\Support\PointBalance;
use App\Models\Area;
use App\Models\Call;
use App\Models\CastProfile;
use App\Models\ClassTier;
use App\Models\IdentityVerification;
use App\Models\PointTransaction;
use App\Models\PointWallet;
use App\Models\User;
use Database\Seeders\OkayamaMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * おひねり（即時消費）と与信ホールドの整合を検証する。
 *
 * 懸念: おひねりで available を使い切ると、完了時の capture で確定残高が
 * マイナスになり台帳の整合が崩れないか。
 */
final class TipAndHoldIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function guest(int $points): User
    {
        $guest = User::create([
            'email' => 'g@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'ゲスト',
        ]);
        IdentityVerification::create([
            'user_id' => $guest->id, 'method' => 'ekyc', 'status' => 'verified',
            'is_adult' => true, 'verified_at' => now(),
        ]);
        $wallet = PointWallet::create(['user_id' => $guest->id]);
        PointTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'purchase', 'kind' => 'paid',
            'points' => $points, 'idempotency_key' => 'seed',
        ]);

        return $guest;
    }

    private function cast(): CastProfile
    {
        $user = User::create([
            'email' => 'c@example.com', 'password' => 'secret-password',
            'role' => 'cast', 'nickname' => 'キャスト',
        ]);
        PointWallet::create(['user_id' => $user->id]);

        return CastProfile::create([
            'user_id' => $user->id, 'display_name' => 'キャスト',
            'class_tier_id' => ClassTier::where('code', 'premium')->value('id'),
            'home_area_id' => Area::where('serviceable', true)->value('id'),
            'screening_status' => 'approved', 'is_active' => true, 'availability' => 'now',
        ]);
    }

    private function makeCall(User $guest): Call
    {
        $this->actingAs($guest)->post(route('calls.store'), [
            'area_id' => Area::where('serviceable', true)->value('id'),
            'start_offset_min' => 30,
            'duration_min' => 60,
            'venue_kind' => 'restaurant',
            'counts' => ['premium' => 1],
        ]);

        return Call::latest('id')->firstOrFail();
    }

    private function balance(User $user): PointBalance
    {
        $wallet = PointWallet::where('user_id', $user->id)->firstOrFail();

        return app(WalletRepository::class)->load($wallet->id)->balance();
    }

    public function test_tip_cannot_consume_points_reserved_by_the_hold(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        // 呼び出し 6,000P の与信 + おひねり原資 5,000P ぴったり
        $guest = $this->guest(11000);
        $cast = $this->cast();
        $call = $this->makeCall($guest);

        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($call, $cast);
        $lifecycle->start($call);

        // 与信 6,000P を除いた利用可能は 5,000P。6,000P のおひねりは通ってはいけない。
        $this->assertSame(5000, $this->balance($guest)->available());

        try {
            $lifecycle->tip($call, 6000);
            $this->fail('与信中のポイントを侵食するおひねりが通ってしまった');
        } catch (\DomainException $e) {
            $this->assertSame('insufficient_points_for_tip', $e->getMessage());
        }
    }

    public function test_completing_after_a_max_tip_keeps_the_ledger_solvent(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest(11000);
        $cast = $this->cast();
        $call = $this->makeCall($guest);

        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($call, $cast);
        $lifecycle->start($call);

        // 利用可能ぶん（5,000P）を使い切るおひねり
        $lifecycle->tip($call, 5000);
        $this->assertSame(0, $this->balance($guest)->available());

        // 完了しても確定残高は 0 で下回らない（与信ぶんが守られている）
        $lifecycle->complete($call);

        $balance = $this->balance($guest);
        $this->assertSame(0, $balance->settled());
        $this->assertSame(0, $balance->outstandingHold());
        $this->assertSame(0, $balance->available());
        $this->assertGreaterThanOrEqual(0, $balance->settled());
    }
}
