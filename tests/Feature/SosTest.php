<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Trust\SosService;
use App\Models\SosEvent;
use App\Models\User;
use App\Support\Adapters\Fake\FakePushSender;
use App\Support\Contracts\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SosTest extends TestCase
{
    use RefreshDatabase;

    private function guest(): User
    {
        return User::create([
            'email' => 'g@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'ゲスト',
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'email' => 'a@example.com', 'password' => 'secret-password',
            'role' => 'admin', 'nickname' => '運営',
        ]);
    }

    public function test_raising_sos_notifies_every_admin(): void
    {
        $guest = $this->guest();
        $admin = $this->admin();
        $push = new FakePushSender;
        $this->app->instance(PushSender::class, $push);

        $this->actingAs($guest)->post(route('sos.store'), [
            'note' => '同席者の言動が怖い',
            'location_hint' => '表町の居酒屋',
        ])->assertRedirect(route('calls.home'));

        $this->assertDatabaseHas('sos_events', [
            'user_id' => $guest->id,
            'status' => 'open',
        ]);
        $this->assertSame(1, $push->countFor($admin->id));
    }

    public function test_repeated_presses_within_ten_minutes_reuse_the_open_event(): void
    {
        $guest = $this->guest();
        $this->admin();
        $service = app(SosService::class);

        $first = $service->raise($guest);
        $second = $service->raise($guest);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SosEvent::count());
    }

    public function test_admin_acknowledges_then_resolves(): void
    {
        $guest = $this->guest();
        $admin = $this->admin();
        $event = app(SosService::class)->raise($guest);

        $this->actingAs($admin)->post(route('admin.sos.ack', $event));
        $event->refresh();
        $this->assertSame('acknowledged', $event->status);
        $this->assertSame($admin->id, $event->handled_by);
        $this->assertNotNull($event->acknowledged_at);

        $this->actingAs($admin)->post(route('admin.sos.resolve', $event), ['note' => '警察へ連携済み']);
        $event->refresh();
        $this->assertSame('resolved', $event->status);
        $this->assertNotNull($event->resolved_at);
    }

    public function test_resolved_event_cannot_be_acknowledged_again(): void
    {
        $guest = $this->guest();
        $admin = $this->admin();
        $event = app(SosService::class)->raise($guest);
        app(SosService::class)->resolve($event, $admin);

        $this->expectException(\DomainException::class);
        app(SosService::class)->acknowledge($event->fresh(), $admin);
    }

    public function test_location_hint_is_hidden_from_serialization(): void
    {
        $guest = $this->guest();
        $event = app(SosService::class)->raise($guest, null, null, '表町の居酒屋');

        // PII は配列化・JSON化で漏れない
        $this->assertArrayNotHasKey('location_hint', $event->toArray());
        // 管理画面では明示的に参照できる
        $this->assertSame('表町の居酒屋', $event->getAttribute('location_hint'));
    }

    public function test_only_admin_can_open_the_sos_console(): void
    {
        $guest = $this->guest();

        $this->actingAs($guest)->get(route('admin.sos.index'))->assertForbidden();
    }

    public function test_sos_form_warns_to_call_emergency_services_first(): void
    {
        $guest = $this->guest();

        $this->actingAs($guest)->get(route('sos.create'))
            ->assertOk()->assertSee('110番');
    }
}
