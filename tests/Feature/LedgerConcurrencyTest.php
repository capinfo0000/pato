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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 金銭の整合を守る仕組みの検証。
 *
 * 「残高を確認してから引き落とす」処理は、ロックを取らないと同時実行で
 * 両方がチェックを通過し、残高以上に消費できてしまう（二重与信）。
 */
final class LedgerConcurrencyTest extends TestCase
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

    private function balance(User $user): PointBalance
    {
        $wallet = PointWallet::where('user_id', $user->id)->firstOrFail();

        return app(WalletRepository::class)->load($wallet->id)->balance();
    }

    public function test_locked_read_returns_the_same_balance_inside_a_transaction(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest(10000);
        $wallet = PointWallet::where('user_id', $guest->id)->firstOrFail();

        DB::transaction(function () use ($wallet) {
            $balance = app(WalletRepository::class)->loadForUpdate($wallet->id)->balance();
            $this->assertSame(10000, $balance->available());
        });
    }

    public function test_call_creation_holds_within_the_balance(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        // プレミアム1時間 = 6,000P。7,000P しか無いので2件目は通らないはず
        $guest = $this->guest(7000);
        $areaId = Area::where('serviceable', true)->value('id');

        $payload = [
            'area_id' => $areaId,
            'start_offset_min' => 30, 'duration_min' => 60,
            'venue_kind' => 'restaurant', 'counts' => ['premium' => 1],
        ];

        $this->actingAs($guest)->post(route('calls.store'), $payload);
        $this->actingAs($guest)->post(route('calls.store'), $payload)->assertSessionHas('error');

        // 与信は1件だけ。残高を超えて確保されていない
        $this->assertSame(1, Call::count());
        $this->assertSame(6000, (int) PointTransaction::where('type', 'hold')->sum('points'));
        $this->assertSame(1000, $this->balance($guest)->available());
        $this->assertGreaterThanOrEqual(0, $this->balance($guest)->available());
    }

    public function test_tip_cannot_exceed_the_available_balance(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest(11000); // 6,000P 与信 → 残り5,000P
        $cast = $this->cast();

        $this->actingAs($guest)->post(route('calls.store'), [
            'area_id' => Area::where('serviceable', true)->value('id'),
            'start_offset_min' => 30, 'duration_min' => 60,
            'venue_kind' => 'restaurant', 'counts' => ['premium' => 1],
        ]);
        $call = Call::latest('id')->firstOrFail();

        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($call, $cast);
        $lifecycle->start($call);

        // 5,000P は通り、続けてもう5,000Pは通らない
        $lifecycle->tip($call, 5000);
        $this->assertSame(0, $this->balance($guest)->available());

        try {
            $lifecycle->tip($call, 5000);
            $this->fail('残高を超えるおひねりが通ってしまった');
        } catch (\DomainException $e) {
            $this->assertSame('insufficient_points_for_tip', $e->getMessage());
        }

        // 確定残高が負にならない
        $this->assertGreaterThanOrEqual(0, $this->balance($guest)->settled());
    }

    public function test_extension_cannot_exceed_the_available_balance(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest(8000); // 6,000P 与信 → 残り2,000P（延長は3,000P必要）
        $cast = $this->cast();

        $this->actingAs($guest)->post(route('calls.store'), [
            'area_id' => Area::where('serviceable', true)->value('id'),
            'start_offset_min' => 30, 'duration_min' => 60,
            'venue_kind' => 'restaurant', 'counts' => ['premium' => 1],
        ]);
        $call = Call::latest('id')->firstOrFail();

        $lifecycle = app(CallLifecycleService::class);
        $lifecycle->participate($call, $cast);
        $lifecycle->start($call);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('insufficient_points_for_extension');
        $lifecycle->extend($call, 30);
    }

    public function test_idempotency_key_prevents_double_posting(): void
    {
        $guest = $this->guest(10000);
        $wallet = PointWallet::where('user_id', $guest->id)->firstOrFail();
        $repo = app(WalletRepository::class);

        $tx = \App\Domain\Point\Support\PointTransaction::hold(1000, callId: 1);
        $repo->append($wallet->id, $tx, 'same-key');

        // 同じ冪等キーでの再計上は DB の一意制約で弾かれる
        $this->expectException(QueryException::class);
        $repo->append($wallet->id, $tx, 'same-key');
    }
}
