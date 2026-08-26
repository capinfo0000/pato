<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Admin\MetricsService;
use App\Application\Call\CallLifecycleService;
use App\Models\Area;
use App\Models\AreaClassPrice;
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

final class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function guest(): User
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
            'points' => 100000, 'idempotency_key' => 'seed',
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

    private function admin(): User
    {
        return User::create([
            'email' => 'a@example.com', 'password' => 'secret-password',
            'role' => 'admin', 'nickname' => '運営',
        ]);
    }

    private function completeOneCall(User $guest, CastProfile $cast): void
    {
        $this->actingAs($guest)->post(route('calls.store'), [
            'area_id' => Area::where('serviceable', true)->value('id'),
            'start_offset_min' => 30, 'duration_min' => 60,
            'venue_kind' => 'restaurant', 'counts' => ['premium' => 1],
        ]);
        $call = Call::latest('id')->firstOrFail();

        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($call, $cast->fresh());
        $lifecycle->start($call);
        $lifecycle->complete($call);
    }

    public function test_metrics_derive_gmv_and_actual_take_rate(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $this->completeOneCall($guest, $cast);

        $summary = app(MetricsService::class)->summary();

        // 6,000P = ¥7,200 / 報酬 4,320P / 取り分 2,880 → 40%
        $this->assertSame(1, $summary['completed']);
        $this->assertSame(6000, $summary['gmv_points']);
        $this->assertSame(7200, $summary['gmv_yen']);
        $this->assertSame(4320, $summary['payout_points']);
        $this->assertSame(2880, $summary['take_points']);
        $this->assertSame(40.0, $summary['take_rate_pct']);
    }

    public function test_dashboard_renders_for_admin(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $this->completeOneCall($guest, $cast);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('テイクレート実績')
            ->assertSee('40%');
    }

    public function test_admin_can_update_pricing(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $admin = $this->admin();
        $price = AreaClassPrice::firstOrFail();

        $this->actingAs($admin)->post(route('admin.prices.update', $price), [
            'points_per_30min' => 3500,
            'take_rate_bp' => 3000,
            'nomination_surcharge_bp' => 2500,
            'night_surcharge_bp' => 1500,
        ])->assertRedirect();

        $price->refresh();
        $this->assertSame(3500, $price->points_per_30min);
        $this->assertSame(3000, $price->take_rate_bp);
    }

    public function test_updated_take_rate_changes_new_call_payouts(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $admin = $this->admin();
        $guest = $this->guest();
        $cast = $this->cast();

        // テイクレートを 40% → 30%（キャスト取り分 70%）に変更
        $price = AreaClassPrice::whereHas('classTier', fn ($q) => $q->where('code', 'premium'))->firstOrFail();
        $this->actingAs($admin)->post(route('admin.prices.update', $price), [
            'points_per_30min' => 3000,
            'take_rate_bp' => 3000,
            'nomination_surcharge_bp' => 2000,
            'night_surcharge_bp' => 2000,
        ]);

        $this->actingAs($guest)->post(route('calls.store'), [
            'area_id' => Area::where('serviceable', true)->value('id'),
            'start_offset_min' => 30, 'duration_min' => 60,
            'venue_kind' => 'restaurant', 'counts' => ['premium' => 1],
        ]);

        // ¥7,200 × 70% = 5,040P
        $this->assertSame(5040, Call::latest('id')->value('cast_payout_points'));
    }

    public function test_toggling_an_area_blocks_new_calls(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $admin = $this->admin();
        $guest = $this->guest();
        $area = Area::where('serviceable', true)->firstOrFail();

        $this->actingAs($admin)->post(route('admin.areas.toggle', $area));
        $this->assertFalse($area->fresh()->serviceable);

        $this->actingAs($guest)->post(route('calls.store'), [
            'area_id' => $area->id,
            'start_offset_min' => 30, 'duration_min' => 60,
            'venue_kind' => 'restaurant', 'counts' => ['premium' => 1],
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('calls', 0);
    }

    public function test_non_admin_cannot_reach_the_dashboard(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();

        $this->actingAs($guest)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($guest)->get(route('admin.prices.index'))->assertForbidden();
    }

    public function test_root_redirects_by_role(): void
    {
        $this->seed(OkayamaMasterSeeder::class);

        $this->actingAs($this->admin())->get('/')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($this->cast()->user)->get('/')->assertRedirect(route('cast.index'));
        $this->actingAs($this->guest())->get('/')->assertRedirect(route('calls.home'));
    }
}
