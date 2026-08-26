<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Call\CallLifecycleService;
use App\Domain\Point\Contracts\WalletRepository;
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

final class CallLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function guest(int $points = 30000): User
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
            'points' => $points, 'idempotency_key' => 'seed-purchase',
        ]);

        return $guest;
    }

    private function cast(string $classCode = 'premium', string $name = 'キャストA'): CastProfile
    {
        $user = User::create([
            'email' => $name.'@example.com', 'password' => 'secret-password',
            'role' => 'cast', 'nickname' => $name,
        ]);
        PointWallet::create(['user_id' => $user->id]);

        return CastProfile::create([
            'user_id' => $user->id,
            'display_name' => $name,
            'class_tier_id' => ClassTier::where('code', $classCode)->value('id'),
            'home_area_id' => Area::where('serviceable', true)->value('id'),
            'screening_status' => 'approved',
            'is_active' => true,
            'availability' => 'now',
        ]);
    }

    private function makeCall(User $guest, int $headcount = 1): Call
    {
        $areaId = Area::where('serviceable', true)->value('id');
        $this->actingAs($guest)->post(route('calls.store'), [
            'area_id' => $areaId,
            'start_offset_min' => 30,
            'duration_min' => 60,
            'venue_kind' => 'restaurant',
            'counts' => ['premium' => $headcount],
        ]);

        return Call::latest('id')->firstOrFail();
    }

    private function available(User $user): int
    {
        $wallet = PointWallet::where('user_id', $user->id)->firstOrFail();

        return app(WalletRepository::class)->load($wallet->id)->balance()->available();
    }

    public function test_full_lifecycle_from_participation_to_payout(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $call = $this->makeCall($guest);

        // プレミアム 3,000P/30分 × 2コマ = 6,000P（キャスト報酬 4,320P）
        $this->assertSame(6000, $call->hold_points);
        $this->assertSame(4320, $call->cast_payout_points);
        $this->assertSame(24000, $this->available($guest)); // 30,000 - 6,000 hold

        $lifecycle = app(CallLifecycleService::class);

        // キャストが参加 → 定員1名なので成立
        $this->assertTrue($lifecycle->participate($call, $cast));
        $this->assertSame('matched', $call->fresh()->status->value);

        // 合流開始 → キャストは合流中に
        $lifecycle->start($call);
        $this->assertSame('in_progress', $call->fresh()->status->value);
        $this->assertTrue($cast->fresh()->in_session);

        // 完了 → ゲストは確定消費、キャストへ報酬計上
        $distribution = $lifecycle->complete($call);

        $this->assertSame('completed', $call->fresh()->status->value);
        $this->assertSame([$cast->id => 4320], $distribution);
        $this->assertSame(24000, $this->available($guest)); // capture 済み
        $this->assertSame(4320, $this->available($cast->user));
        $this->assertFalse($cast->fresh()->in_session);
        $this->assertDatabaseHas('payout_items', ['amount_points' => 4320]);
        // ファンポイント（4,320P → 43FP）
        $this->assertDatabaseHas('fan_points', ['cast_profile_id' => $cast->id, 'points' => 43]);
    }

    public function test_cancel_releases_the_hold(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $call = $this->makeCall($guest);

        $this->assertSame(24000, $this->available($guest));

        app(CallLifecycleService::class)->release($call);

        $this->assertSame('canceled', $call->fresh()->status->value);
        $this->assertSame(30000, $this->available($guest)); // 全額戻る
    }

    public function test_tip_is_split_between_participants(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $castA = $this->cast('premium', 'キャストA');
        $castB = $this->cast('premium', 'キャストB');
        $call = $this->makeCall($guest, headcount: 2);

        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($call, $castA);
        $lifecycle->participate($call, $castB);
        $lifecycle->start($call);

        $distribution = $lifecycle->tip($call, 6000);

        $this->assertSame([$castA->id => 3000, $castB->id => 3000], $distribution);
        $this->assertSame(3000, $this->available($castA->user));
        $this->assertSame(3000, $this->available($castB->user));
    }

    public function test_tip_below_minimum_is_rejected(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $call = $this->makeCall($guest);

        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($call, $cast);
        $lifecycle->start($call);

        $this->expectException(\DomainException::class);
        $lifecycle->tip($call, 1000);
    }

    public function test_cast_in_session_cannot_join_another_call(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();

        $first = $this->makeCall($guest);
        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($first, $cast);
        $lifecycle->start($first);

        $second = $this->makeCall($guest);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('cast_in_session');
        $lifecycle->participate($second, $cast->fresh());
    }

    public function test_illegal_transition_is_blocked(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $call = $this->makeCall($guest); // open のまま

        $this->expectException(\DomainException::class);
        app(CallLifecycleService::class)->complete($call); // open → completed は不可
    }

    public function test_stale_open_calls_expire_and_release(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $call = $this->makeCall($guest);
        $call->update(['start_at' => now()->subHour()]);

        $count = app(CallLifecycleService::class)->expireStaleCalls();

        $this->assertSame(1, $count);
        $this->assertSame('expired', $call->fresh()->status->value);
        $this->assertSame(30000, $this->available($guest));
    }
}
