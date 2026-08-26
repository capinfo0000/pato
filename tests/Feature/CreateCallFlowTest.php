<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Call;
use App\Models\IdentityVerification;
use App\Models\PointTransaction;
use App\Models\PointWallet;
use App\Models\User;
use Database\Seeders\OkayamaMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateCallFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeVerifiedGuest(int $points = 30000): User
    {
        $guest = User::create([
            'email' => 'g@example.com',
            'password' => 'secret-password',
            'role' => 'guest',
            'nickname' => 'テストゲスト',
        ]);
        IdentityVerification::create([
            'user_id' => $guest->id,
            'method' => 'ekyc',
            'status' => 'verified',
            'is_adult' => true,
            'verified_at' => now(),
        ]);
        $wallet = PointWallet::create(['user_id' => $guest->id]);
        if ($points > 0) {
            PointTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'purchase', 'kind' => 'paid', 'points' => $points,
                'idempotency_key' => 'test-purchase',
            ]);
        }

        return $guest;
    }

    public function test_guest_can_create_call_and_points_are_held(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->makeVerifiedGuest();
        $areaId = Area::where('serviceable', true)->value('id');

        $payload = [
            'area_id' => $areaId,
            'start_offset_min' => 30,
            'duration_min' => 60,
            'venue_kind' => 'restaurant',
            'counts' => ['premium' => 1, 'vip' => 0, 'royal_vip' => 0],
        ];

        $response = $this->actingAs($guest)->post(route('calls.store'), $payload);

        // プレミアム 3,000P/30分 × 2コマ = 6,000P
        $this->assertDatabaseHas('calls', [
            'guest_user_id' => $guest->id,
            'status' => 'open',
            'hold_points' => 6000,
        ]);
        $this->assertDatabaseHas('point_transactions', [
            'type' => 'hold',
            'points' => 6000,
        ]);

        $call = Call::first();
        $response->assertRedirect(route('calls.show', $call));
    }

    public function test_confirm_shows_quote_without_persisting(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->makeVerifiedGuest();
        $areaId = Area::where('serviceable', true)->value('id');

        $this->actingAs($guest)->post(route('calls.confirm'), [
            'area_id' => $areaId,
            'start_offset_min' => 30,
            'duration_min' => 60,
            'venue_kind' => 'bar',
            'counts' => ['royal_vip' => 1, 'vip' => 1, 'premium' => 0],
        ])->assertOk()->assertSee('31,000P'); // RoyalVIP 20,000 + VIP 11,000 (1時間)

        $this->assertDatabaseCount('calls', 0);
    }

    public function test_insufficient_points_are_rejected(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->makeVerifiedGuest(points: 1000);
        $areaId = Area::where('serviceable', true)->value('id');

        $this->actingAs($guest)->post(route('calls.store'), [
            'area_id' => $areaId,
            'start_offset_min' => 30,
            'duration_min' => 60,
            'venue_kind' => 'restaurant',
            'counts' => ['premium' => 1],
        ])->assertRedirect();

        $this->assertDatabaseCount('calls', 0);
        $this->assertDatabaseMissing('point_transactions', ['type' => 'hold']);
    }

    public function test_unverified_guest_is_blocked(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = User::create([
            'email' => 'nov@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => '未確認',
        ]);
        PointWallet::create(['user_id' => $guest->id]);
        $areaId = Area::where('serviceable', true)->value('id');

        $this->actingAs($guest)->post(route('calls.store'), [
            'area_id' => $areaId,
            'start_offset_min' => 30,
            'duration_min' => 60,
            'venue_kind' => 'restaurant',
            'counts' => ['premium' => 1],
        ])->assertRedirect();

        $this->assertDatabaseCount('calls', 0);
    }

    public function test_home_requires_login(): void
    {
        $this->get(route('calls.home'))->assertRedirect(route('login'));
    }
}
