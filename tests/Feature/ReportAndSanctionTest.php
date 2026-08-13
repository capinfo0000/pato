<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Call\CallLifecycleService;
use App\Application\Trust\ReportService;
use App\Models\Area;
use App\Models\Call;
use App\Models\CastProfile;
use App\Models\ClassTier;
use App\Models\IdentityVerification;
use App\Models\PointTransaction;
use App\Models\PointWallet;
use App\Models\Report;
use App\Models\Thread;
use App\Models\User;
use Database\Seeders\OkayamaMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReportAndSanctionTest extends TestCase
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
            'points' => 30000, 'idempotency_key' => 'seed',
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

    public function test_guest_can_report_a_cast(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();

        $this->actingAs($guest)->post(route('reports.store'), [
            'target_user_id' => $cast->user_id,
            'reason' => 'harassment',
            'detail' => '不快な言動がありました',
        ])->assertRedirect(route('calls.home'));

        $this->assertDatabaseHas('reports', [
            'reporter_user_id' => $guest->id,
            'target_user_id' => $cast->user_id,
            'reason' => 'harassment',
            'status' => 'open',
        ]);
    }

    public function test_duplicate_open_report_is_rejected(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();

        $payload = ['target_user_id' => $cast->user_id, 'reason' => 'danger'];
        $this->actingAs($guest)->post(route('reports.store'), $payload);
        $this->actingAs($guest)->post(route('reports.store'), $payload)->assertSessionHas('error');

        $this->assertSame(1, Report::count());
    }

    public function test_admin_actions_a_report_and_suspends_the_account(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $admin = $this->admin();

        $report = app(ReportService::class)->report($guest, $cast->user, 'danger', '危険を感じた');

        $this->actingAs($admin)->post(route('admin.reports.review', $report));
        $this->assertSame('reviewing', $report->fresh()->status);

        $this->actingAs($admin)->post(route('admin.reports.action', $report), ['suspend' => '1']);

        $this->assertSame('actioned', $report->fresh()->status);
        $this->assertSame('suspended', $cast->user->fresh()->status);
    }

    public function test_suspended_cast_cannot_participate(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();

        $this->actingAs($guest)->post(route('calls.store'), [
            'area_id' => Area::where('serviceable', true)->value('id'),
            'start_offset_min' => 30, 'duration_min' => 60,
            'venue_kind' => 'restaurant', 'counts' => ['premium' => 1],
        ]);
        $call = Call::latest('id')->firstOrFail();

        app(ReportService::class)->suspend($cast->user_id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('cast_suspended');
        app(CallLifecycleService::class)->participate($call, $cast->fresh());
    }

    public function test_suspended_guest_cannot_create_a_call(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();

        app(ReportService::class)->suspend($guest->id);

        $this->actingAs($guest->fresh())->post(route('calls.store'), [
            'area_id' => Area::where('serviceable', true)->value('id'),
            'start_offset_min' => 30, 'duration_min' => 60,
            'venue_kind' => 'restaurant', 'counts' => ['premium' => 1],
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('calls', 0);
    }

    public function test_reinstating_restores_access(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $admin = $this->admin();

        $report = app(ReportService::class)->report($guest, $cast->user, 'other');
        $this->actingAs($admin)->post(route('admin.reports.action', $report), ['suspend' => '1']);
        $this->assertSame('suspended', $cast->user->fresh()->status);

        $this->actingAs($admin)->post(route('admin.reports.reinstate', $report));
        $this->assertSame('active', $cast->user->fresh()->status);
    }

    public function test_flagged_messages_are_visible_to_admin(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $admin = $this->admin();

        $this->actingAs($guest)->post(route('calls.store'), [
            'area_id' => Area::where('serviceable', true)->value('id'),
            'start_offset_min' => 30, 'duration_min' => 60,
            'venue_kind' => 'restaurant', 'counts' => ['premium' => 1],
        ]);
        $call = Call::latest('id')->firstOrFail();
        app(CallLifecycleService::class)->participate($call, $cast);

        $thread = Thread::where('call_id', $call->id)->firstOrFail();
        $this->actingAs($guest)->post(route('messages.store', $thread), [
            'body' => '現金手渡しでお願いします',
        ]);

        $this->actingAs($admin)->get(route('admin.reports.index'))
            ->assertOk()->assertSee('cash_direct');
    }

    public function test_only_admin_can_open_the_report_queue(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();

        $this->actingAs($guest)->get(route('admin.reports.index'))->assertForbidden();
    }

    public function test_cannot_report_self(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();

        $this->actingAs($guest)->post(route('reports.store'), [
            'target_user_id' => $guest->id,
            'reason' => 'other',
        ])->assertSessionHas('error');

        $this->assertSame(0, Report::count());
    }
}
