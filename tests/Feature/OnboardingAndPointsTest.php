<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Point\Contracts\WalletRepository;
use App\Models\Area;
use App\Models\CastProfile;
use App\Models\ClassTier;
use App\Models\PointProduct;
use App\Models\PointTransaction;
use App\Models\PointWallet;
use App\Models\User;
use Database\Seeders\OkayamaMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OnboardingAndPointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_user_and_wallet_then_asks_for_verification(): void
    {
        $this->post(route('register'), [
            'nickname' => 'たろう',
            'email' => 'new@example.com',
            'password' => 'password123',
            'role' => 'guest',
            'agree' => '1',
        ])->assertRedirect(route('verify.show'));

        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'role' => 'guest', 'nickname' => 'たろう']);
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertDatabaseHas('point_wallets', ['user_id' => $user->id]);
    }

    public function test_registration_requires_agreement(): void
    {
        $this->post(route('register'), [
            'nickname' => 'たろう',
            'email' => 'x@example.com',
            'password' => 'password123',
            'role' => 'guest',
        ])->assertSessionHasErrors('agree');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_adult_verification_succeeds(): void
    {
        $user = User::create([
            'email' => 'a@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'おとな',
        ]);

        $this->actingAs($user)->post(route('verify.submit'), [
            'birthdate' => now()->subYears(25)->toDateString(),
        ])->assertRedirect(route('calls.home'));

        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $user->id, 'status' => 'verified', 'is_adult' => true,
        ]);
    }

    public function test_minor_verification_is_rejected(): void
    {
        $user = User::create([
            'email' => 'm@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'みしょう',
        ]);

        $this->actingAs($user)->post(route('verify.submit'), [
            'birthdate' => now()->subYears(16)->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $user->id, 'status' => 'rejected', 'is_adult' => false,
        ]);
    }

    public function test_purchasing_points_credits_the_ledger_with_expiry(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $user = User::create([
            'email' => 'p@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'かいもの',
        ]);
        $product = PointProduct::where('paid_points', 10000)->firstOrFail();

        $this->actingAs($user)->post(route('points.purchase'), [
            'product_id' => $product->id,
        ])->assertRedirect(route('points.index'));

        $wallet = PointWallet::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(10000, app(WalletRepository::class)->load($wallet->id)->balance()->available());
        $tx = PointTransaction::where('wallet_id', $wallet->id)->firstOrFail();
        $this->assertSame('purchase', $tx->type->value);
        $this->assertSame(10000, $tx->points);
        $this->assertSame(now()->addDays(180)->toDateString(), $tx->expires_on->toDateString());
    }

    public function test_cast_directory_filters_by_class_and_availability(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = User::create([
            'email' => 'd@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'さがす',
        ]);
        $areaId = Area::where('serviceable', true)->value('id');

        foreach ([['premium', 'ぷれみあ', 'now'], ['vip', 'ぶいあい', 'offline']] as [$code, $name, $availability]) {
            $u = User::create([
                'email' => $name.'@example.com', 'password' => 'secret-password',
                'role' => 'cast', 'nickname' => $name,
            ]);
            CastProfile::create([
                'user_id' => $u->id, 'display_name' => $name,
                'class_tier_id' => ClassTier::where('code', $code)->value('id'),
                'home_area_id' => $areaId, 'screening_status' => 'approved',
                'is_active' => true, 'availability' => $availability, 'age' => 25,
            ]);
        }

        $this->actingAs($guest)->get(route('casts.index', ['class' => 'premium']))
            ->assertOk()->assertSee('ぷれみあ')->assertDontSee('ぶいあい');

        $this->actingAs($guest)->get(route('casts.index', ['availability' => 'now']))
            ->assertOk()->assertSee('ぷれみあ')->assertDontSee('ぶいあい');
    }

    public function test_cast_cannot_reach_guest_pages(): void
    {
        $cast = User::create([
            'email' => 'c@example.com', 'password' => 'secret-password',
            'role' => 'cast', 'nickname' => 'きゃすと',
        ]);

        $this->actingAs($cast)->get(route('calls.home'))->assertForbidden();
    }

    public function test_guest_cannot_reach_cast_pages(): void
    {
        $guest = User::create([
            'email' => 'g2@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'げすと',
        ]);

        $this->actingAs($guest)->get(route('cast.index'))->assertForbidden();
    }
}
