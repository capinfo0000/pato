<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Call\CallLifecycleService;
use App\Application\Trust\ReviewService;
use App\Domain\Point\Contracts\WalletRepository;
use App\Models\Area;
use App\Models\Call;
use App\Models\CastProfile;
use App\Models\ClassTier;
use App\Models\IdentityVerification;
use App\Models\PointTransaction;
use App\Models\PointWallet;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\OkayamaMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExtendNominateReviewTest extends TestCase
{
    use RefreshDatabase;

    private function guest(int $points = 100000): User
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

    private function cast(string $name = 'キャスト'): CastProfile
    {
        $user = User::create([
            'email' => $name.'@example.com', 'password' => 'secret-password',
            'role' => 'cast', 'nickname' => $name,
        ]);
        PointWallet::create(['user_id' => $user->id]);

        return CastProfile::create([
            'user_id' => $user->id, 'display_name' => $name,
            'class_tier_id' => ClassTier::where('code', 'premium')->value('id'),
            'home_area_id' => Area::where('serviceable', true)->value('id'),
            'screening_status' => 'approved', 'is_active' => true, 'availability' => 'now',
        ]);
    }

    private function makeCall(User $guest, array $extra = []): Call
    {
        $this->actingAs($guest)->post(route('calls.store'), array_merge([
            'area_id' => Area::where('serviceable', true)->value('id'),
            'start_offset_min' => 30, 'duration_min' => 60,
            'venue_kind' => 'restaurant', 'counts' => ['premium' => 1],
        ], $extra));

        return Call::latest('id')->firstOrFail();
    }

    private function available(User $user): int
    {
        $wallet = PointWallet::where('user_id', $user->id)->firstOrFail();

        return app(WalletRepository::class)->load($wallet->id)->balance()->available();
    }

    public function test_extension_adds_hold_and_payout(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $call = $this->makeCall($guest);

        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($call, $cast);
        $lifecycle->start($call);

        // 1時間 6,000P → 30分延長で +3,000P（報酬 +2,160P）
        $result = $lifecycle->extend($call, 30);

        $this->assertSame(3000, $result['added_hold']);
        $this->assertSame(2160, $result['added_payout']);

        $call->refresh();
        $this->assertSame(90, $call->duration_min);
        $this->assertSame(9000, $call->hold_points);
        $this->assertSame(6480, $call->cast_payout_points);
        $this->assertSame(100000 - 9000, $this->available($guest));
    }

    public function test_extension_capture_matches_the_extended_total(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $call = $this->makeCall($guest);

        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($call, $cast);
        $lifecycle->start($call);
        $lifecycle->extend($call, 60);
        $distribution = $lifecycle->complete($call->fresh());

        // 2時間ぶん: ゲスト12,000P / キャスト8,640P
        $this->assertSame(100000 - 12000, $this->available($guest));
        $this->assertSame([$cast->id => 8640], $distribution);
    }

    public function test_extension_requires_in_progress(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $call = $this->makeCall($guest); // open のまま

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not_in_progress');
        app(CallLifecycleService::class)->extend($call, 30);
    }

    public function test_extension_over_balance_is_rejected(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest(points: 7000); // 6,000P の呼び出しで残り1,000P
        $cast = $this->cast();
        $call = $this->makeCall($guest);

        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($call, $cast);
        $lifecycle->start($call);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('insufficient_points_for_extension');
        $lifecycle->extend($call, 30);
    }

    public function test_nomination_is_recorded_and_surcharged(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();

        $call = $this->makeCall($guest, [
            'nominated_cast_profile_id' => $cast->id,
            'nominate' => ['premium' => 1], // 指名加算 +20%
        ]);

        $this->assertSame($cast->id, $call->nominated_cast_profile_id);
        // 6,000P × 1.2 = 7,200P
        $this->assertSame(7200, $call->hold_points);
    }

    public function test_review_is_posted_and_updates_kpi(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $call = $this->makeCall($guest);

        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($call, $cast);
        $lifecycle->start($call);
        $lifecycle->complete($call);

        $this->actingAs($guest)->post(route('calls.review', $call), [
            'ratee_user_id' => $cast->user_id,
            'stars' => 5,
            'tags' => ['また会いたい', '話が面白い'],
            'comment' => 'ありがとうございました',
        ])->assertRedirect(route('calls.show', $call));

        $this->assertDatabaseHas('reviews', [
            'call_id' => $call->id,
            'rater_user_id' => $guest->id,
            'ratee_user_id' => $cast->user_id,
            'stars' => 5,
        ]);

        // 「また会いたい」100% → 星5.0（x10 で 50）
        $this->assertSame(50, $cast->fresh()->kpi->remeet_rate_x10);
    }

    public function test_review_requires_completion_and_is_once_only(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $call = $this->makeCall($guest);

        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($call, $cast);
        $lifecycle->start($call);

        // 完了前は不可
        $this->actingAs($guest)->post(route('calls.review', $call), [
            'ratee_user_id' => $cast->user_id, 'stars' => 5,
        ])->assertSessionHas('error');

        $lifecycle->complete($call);

        $this->actingAs($guest)->post(route('calls.review', $call), [
            'ratee_user_id' => $cast->user_id, 'stars' => 4,
        ])->assertSessionHas('status');

        // 2回目は拒否
        $this->actingAs($guest)->post(route('calls.review', $call), [
            'ratee_user_id' => $cast->user_id, 'stars' => 1,
        ])->assertSessionHas('error');

        $this->assertSame(1, Review::count());
    }

    public function test_extension_kpi_is_reflected(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $call = $this->makeCall($guest);

        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($call, $cast);
        $lifecycle->start($call);
        $lifecycle->extend($call, 30);
        $lifecycle->complete($call->fresh());

        // 延長した呼び出しが1件/1件 → 延長率 星5.0
        $kpi = app(ReviewService::class)->recalculateKpi($cast);
        $this->assertSame(50, $kpi->extend_rate_x10);
    }
}
