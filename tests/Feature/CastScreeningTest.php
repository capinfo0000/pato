<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Cast\ScreeningService;
use App\Models\Area;
use App\Models\CastProfile;
use App\Models\CastScreening;
use App\Models\User;
use Database\Seeders\OkayamaMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CastScreeningTest extends TestCase
{
    use RefreshDatabase;

    private function castUser(string $email = 'c@example.com'): User
    {
        return User::create([
            'email' => $email, 'password' => 'secret-password',
            'role' => 'cast', 'nickname' => 'あおい',
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'email' => 'admin@example.com', 'password' => 'secret-password',
            'role' => 'admin', 'nickname' => '運営',
        ]);
    }

    public function test_cast_applies_and_lands_in_the_queue(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $cast = $this->castUser();
        $areaId = Area::where('serviceable', true)->value('id');

        $this->actingAs($cast)->post(route('cast.apply.store'), [
            'display_name' => 'あおい',
            'home_area_id' => $areaId,
            'age' => 24,
            'bio' => 'よろしくお願いします',
        ])->assertRedirect(route('cast.apply.show'));

        $this->assertDatabaseHas('cast_profiles', [
            'user_id' => $cast->id, 'screening_status' => 'applied', 'is_active' => false,
        ]);
        $this->assertDatabaseHas('cast_screenings', ['stage' => 'photo', 'result' => 'pending']);
    }

    public function test_unapproved_cast_is_redirected_to_the_application(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $cast = $this->castUser();

        $this->actingAs($cast)->get(route('cast.index'))->assertRedirect(route('cast.apply.show'));
    }

    public function test_admin_advances_then_approves_with_a_class(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $cast = $this->castUser();
        $admin = $this->admin();
        $service = app(ScreeningService::class);
        $profile = $service->apply($cast, 'あおい', Area::where('serviceable', true)->value('id'), 24, null);

        // applied → photo_review
        $this->actingAs($admin)->post(route('admin.screenings.advance', $profile), ['note' => '写真OK']);
        $this->assertSame('photo_review', $profile->fresh()->screening_status);

        // photo_review → interview
        $this->actingAs($admin)->post(route('admin.screenings.advance', $profile));
        $this->assertSame('interview', $profile->fresh()->screening_status);

        // 承認（クラス付与で稼働可能に）
        $this->actingAs($admin)->post(route('admin.screenings.approve', $profile), [
            'class' => 'vip', 'note' => '面談良好',
        ])->assertRedirect(route('admin.screenings.show', $profile));

        $profile = $profile->fresh();
        $this->assertSame('approved', $profile->screening_status);
        $this->assertTrue($profile->is_active);
        $this->assertSame('vip', $profile->classTier->code);

        // 承認後は募集一覧が開ける
        $this->actingAs($cast)->get(route('cast.index'))->assertOk();
    }

    public function test_admin_can_reject_and_cast_cannot_immediately_reapply(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $cast = $this->castUser();
        $admin = $this->admin();
        $service = app(ScreeningService::class);
        $profile = $service->apply($cast, 'あおい', Area::where('serviceable', true)->value('id'), 24, null);

        $this->actingAs($admin)->post(route('admin.screenings.reject', $profile), ['note' => '基準未達']);

        $profile = $profile->fresh();
        $this->assertSame('rejected', $profile->screening_status);
        $this->assertFalse($profile->is_active);

        // 3か月経っていないので再申込不可
        $this->assertFalse($service->canReapply($profile));
        $this->actingAs($cast)->post(route('cast.apply.store'), [
            'display_name' => 'あおい', 'home_area_id' => $profile->home_area_id, 'age' => 24,
        ])->assertSessionHas('error');
    }

    public function test_reapplication_is_allowed_after_the_waiting_period(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $cast = $this->castUser();
        $admin = $this->admin();
        $service = app(ScreeningService::class);
        $profile = $service->apply($cast, 'あおい', Area::where('serviceable', true)->value('id'), 24, null);
        $service->reject($profile, $admin);

        // 却下から3か月経過させる
        CastScreening::where('cast_profile_id', $profile->id)
            ->where('result', 'failed')
            ->update(['reviewed_at' => now()->subMonths(ScreeningService::REAPPLY_AFTER_MONTHS + 1)]);

        $this->assertTrue($service->canReapply($profile->fresh()));

        $this->actingAs($cast)->post(route('cast.apply.store'), [
            'display_name' => 'あおい', 'home_area_id' => $profile->home_area_id, 'age' => 24,
        ])->assertSessionMissing('error');

        $this->assertSame('applied', $profile->fresh()->screening_status);
    }

    public function test_only_admins_can_reach_the_queue(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $cast = $this->castUser();

        $this->actingAs($cast)->get(route('admin.screenings.index'))->assertForbidden();
    }

    public function test_unapproved_cast_cannot_be_matched(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $cast = $this->castUser();
        $service = app(ScreeningService::class);
        $profile = $service->apply($cast, 'あおい', Area::where('serviceable', true)->value('id'), 24, null);

        // 承認前は参加表明できない（CallLifecycleService のガード）
        $this->assertFalse(CastProfile::find($profile->id)->is_active);
    }
}
