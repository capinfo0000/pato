<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Call\CallLifecycleService;
use App\Application\Messaging\PostMessageService;
use App\Models\Area;
use App\Models\Call;
use App\Models\CastProfile;
use App\Models\ClassTier;
use App\Models\IdentityVerification;
use App\Models\Message;
use App\Models\PointTransaction;
use App\Models\PointWallet;
use App\Models\Thread;
use App\Models\User;
use Database\Seeders\OkayamaMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MessagingTest extends TestCase
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

    private function cast(string $name = 'キャストA'): CastProfile
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

    private function makeCall(User $guest): Call
    {
        $this->actingAs($guest)->post(route('calls.store'), [
            'area_id' => Area::where('serviceable', true)->value('id'),
            'start_offset_min' => 30, 'duration_min' => 60,
            'venue_kind' => 'restaurant', 'counts' => ['premium' => 1],
        ]);

        return Call::latest('id')->firstOrFail();
    }

    public function test_matching_opens_a_group_thread_with_both_sides(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $call = $this->makeCall($guest);

        app(CallLifecycleService::class)->participate($call, $cast);

        $thread = Thread::where('call_id', $call->id)->firstOrFail();
        $this->assertSame('call', $thread->kind);
        $this->assertEqualsCanonicalizing(
            [$guest->id, $cast->user_id],
            $thread->participants()->pluck('user_id')->all(),
        );
    }

    public function test_participants_can_post_and_read_messages(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $call = $this->makeCall($guest);
        app(CallLifecycleService::class)->participate($call, $cast);
        $thread = Thread::where('call_id', $call->id)->firstOrFail();

        $this->actingAs($guest)->post(route('messages.store', $thread), [
            'body' => '本日はよろしくお願いします',
        ])->assertRedirect(route('messages.show', $thread));

        $this->assertDatabaseHas('messages', [
            'thread_id' => $thread->id,
            'sender_user_id' => $guest->id,
            'flagged' => false,
        ]);

        // キャスト側でも読める
        $this->actingAs($cast->user)->get(route('messages.show', $thread))
            ->assertOk()->assertSee('本日はよろしくお願いします');
    }

    public function test_outsiders_cannot_read_a_thread(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $call = $this->makeCall($guest);
        app(CallLifecycleService::class)->participate($call, $cast);
        $thread = Thread::where('call_id', $call->id)->firstOrFail();

        $stranger = User::create([
            'email' => 'x@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => '他人',
        ]);

        $this->actingAs($stranger)->get(route('messages.show', $thread))->assertForbidden();
    }

    public function test_prohibited_content_is_flagged_and_warns_the_sender(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $call = $this->makeCall($guest);
        app(CallLifecycleService::class)->participate($call, $cast);
        $thread = Thread::where('call_id', $call->id)->firstOrFail();

        $this->actingAs($guest)->post(route('messages.store', $thread), [
            'body' => 'この後ホテルでどう？現金手渡しでいいよ',
        ])->assertSessionHas('error');

        $message = Message::latest('id')->firstOrFail();
        $this->assertTrue($message->flagged);
        $this->assertContains('private_room', $message->flag_reasons);
        $this->assertContains('cash_direct', $message->flag_reasons);
    }

    public function test_contact_exchange_is_flagged_without_blocking(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $cast = $this->cast();
        $call = $this->makeCall($guest);
        app(CallLifecycleService::class)->participate($call, $cast);
        $thread = Thread::where('call_id', $call->id)->firstOrFail();

        // 連絡先交換は記録するが、警告は出さない（誤検知で会話を止めない方針）
        $this->actingAs($guest)->post(route('messages.store', $thread), [
            'body' => '090-1234-5678 に連絡ください',
        ])->assertSessionMissing('error');

        $message = Message::latest('id')->firstOrFail();
        $this->assertTrue($message->flagged);
        $this->assertContains('contact_exchange', $message->flag_reasons);
    }

    public function test_concierge_thread_is_created_on_first_visit(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();

        $this->actingAs($guest)->get(route('messages.index'))
            ->assertOk()->assertSee('patoコンシェルジュ');

        $this->assertDatabaseHas('threads', ['kind' => 'concierge']);
        $this->assertDatabaseHas('users', ['role' => 'system', 'nickname' => 'patoコンシェルジュ']);
    }

    public function test_favorite_and_hidden_filters(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $guest = $this->guest();
        $thread = app(PostMessageService::class)->ensureConciergeThread($guest);

        $this->actingAs($guest)->post(route('messages.toggle', $thread), ['field' => 'is_favorite']);
        $this->actingAs($guest)->get(route('messages.index', ['filter' => 'favorite']))
            ->assertOk()->assertSee('patoコンシェルジュ');

        $this->actingAs($guest)->post(route('messages.toggle', $thread), ['field' => 'is_hidden']);
        // 非表示にすると「すべて」からは消え、「非表示」タブで見える
        $this->actingAs($guest)->get(route('messages.index', ['filter' => 'hidden']))
            ->assertOk()->assertSee('patoコンシェルジュ');
    }
}
