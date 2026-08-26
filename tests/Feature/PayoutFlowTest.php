<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Call\CallLifecycleService;
use App\Application\Payout\PayoutService;
use App\Domain\Point\Contracts\WalletRepository;
use App\Models\Area;
use App\Models\Call;
use App\Models\CastProfile;
use App\Models\ClassTier;
use App\Models\IdentityVerification;
use App\Models\Payout;
use App\Models\PointTransaction;
use App\Models\PointWallet;
use App\Models\User;
use Database\Seeders\OkayamaMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PayoutFlowTest extends TestCase
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

    /** 完了した呼び出しを1件作り、キャストに報酬を計上する（4,320P）。 */
    private function completeOneCall(User $guest, CastProfile $cast): Call
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

        return $call;
    }

    private function castBalance(CastProfile $cast): int
    {
        $wallet = PointWallet::where('user_id', $cast->user_id)->firstOrFail();

        return app(WalletRepository::class)->load($wallet->id)->balance()->available();
    }

    public function test_full_payout_flow_request_approve_pay(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $admin = $this->admin();

        $this->completeOneCall($guest, $cast);
        $service = app(PayoutService::class);

        $this->assertSame(4320, $service->pendingPoints($cast));
        $this->assertSame(4320, $this->castBalance($cast));

        // 申請
        $payout = $service->request($cast, 'normal');
        $this->assertSame('requested', $payout->status);
        $this->assertSame(4320, $payout->amount_points);
        $this->assertSame(0, $service->pendingPoints($cast)); // 明細は申請に紐づく

        // 承認
        $service->approve($payout, $admin);
        $this->assertSame('approved', $payout->fresh()->status);

        // 送金完了 → 台帳から引き落とされる
        $service->markPaid($payout->fresh());
        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertSame(0, $this->castBalance($cast));
        $this->assertDatabaseHas('point_transactions', ['type' => 'payout_debit', 'points' => 4320]);
    }

    public function test_request_below_minimum_is_rejected(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $cast = $this->cast();

        // 報酬がまったく無い状態では申請できない
        $service = app(PayoutService::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('below_minimum');
        $service->request($cast);
    }

    public function test_express_payout_deducts_a_fee(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();

        $this->completeOneCall($guest, $cast);
        $payout = app(PayoutService::class)->request($cast, 'express');

        $this->assertSame(4320 - PayoutService::EXPRESS_FEE_POINTS, $payout->amount_points);
        $this->assertSame('express', $payout->speed);
    }

    public function test_rejecting_returns_items_to_the_pending_pool(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();

        $this->completeOneCall($guest, $cast);
        $service = app(PayoutService::class);
        $payout = $service->request($cast);

        $service->reject($payout);

        $this->assertSame('rejected', $payout->fresh()->status);
        $this->assertSame(4320, $service->pendingPoints($cast)); // 再申請できる
    }

    public function test_cannot_pay_before_approval(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();

        $this->completeOneCall($guest, $cast);
        $payout = app(PayoutService::class)->request($cast);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not_approved');
        app(PayoutService::class)->markPaid($payout);
    }

    public function test_cast_can_request_via_http_and_admin_sees_it(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $admin = $this->admin();

        $this->completeOneCall($guest, $cast);

        $this->actingAs($cast->user)->post(route('cast.payouts.request'), ['speed' => 'normal'])
            ->assertRedirect(route('cast.payouts'));

        $this->assertDatabaseHas('payouts', ['status' => 'requested', 'amount_points' => 4320]);

        $payout = Payout::firstOrFail();
        $this->actingAs($admin)->get(route('admin.payouts.index'))->assertOk()->assertSee('4,320P');

        $this->actingAs($admin)->post(route('admin.payouts.approve', $payout));
        $this->actingAs($admin)->post(route('admin.payouts.paid', $payout->fresh()));

        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertSame(0, $this->castBalance($cast));
    }

    public function test_guest_cannot_reach_payout_admin(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();

        $this->actingAs($guest)->get(route('admin.payouts.index'))->assertForbidden();
    }
}
