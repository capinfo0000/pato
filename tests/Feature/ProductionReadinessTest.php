<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Cast\ScreeningService;
use App\Application\Trust\ReportService;
use App\Jobs\ComputeRankingsJob;
use App\Jobs\ExpireStaleCallsJob;
use App\Logging\MaskPii;
use App\Models\Area;
use App\Models\AreaClassPrice;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\OkayamaMasterSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

final class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'email' => 'a@example.com', 'password' => 'secret-password',
            'role' => 'admin', 'nickname' => '運営',
        ]);
    }

    // --- ヘルスチェック ---

    public function test_health_endpoint_reports_dependencies(): void
    {
        $this->get('/healthz')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.cache', true);
    }

    // --- 監査ログ ---

    public function test_suspension_is_audited(): void
    {
        $admin = $this->admin();
        $target = User::create([
            'email' => 't@example.com', 'password' => 'secret-password',
            'role' => 'cast', 'nickname' => '対象',
        ]);

        $this->actingAs($admin);
        app(ReportService::class)->suspend($target->id);

        $log = AuditLog::where('action', 'user.suspended')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame($target->id, (int) $log->subject_id);
    }

    public function test_price_change_is_audited_with_before_and_after(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $admin = $this->admin();
        $price = AreaClassPrice::firstOrFail();

        $this->actingAs($admin)->post(route('admin.prices.update', $price), [
            'points_per_30min' => 3500,
            'take_rate_bp' => 3000,
            'nomination_surcharge_bp' => 2000,
            'night_surcharge_bp' => 2000,
        ]);

        $log = AuditLog::where('action', 'price.updated')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertStringContainsString('3000', (string) $log->context['after']);
    }

    public function test_screening_decision_is_audited(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $admin = $this->admin();
        $cast = User::create([
            'email' => 'c@example.com', 'password' => 'secret-password',
            'role' => 'cast', 'nickname' => 'キャスト',
        ]);

        $this->actingAs($admin);
        $service = app(ScreeningService::class);
        $profile = $service->apply($cast, 'あおい', Area::where('serviceable', true)->value('id'), 24, null);
        $service->approve($profile, $admin, 'vip');

        $log = AuditLog::where('action', 'screening.approved')->firstOrFail();
        $this->assertSame('vip', $log->context['class']);
    }

    public function test_audit_logs_are_append_only_in_practice(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $target = User::create([
            'email' => 't2@example.com', 'password' => 'secret-password',
            'role' => 'cast', 'nickname' => '対象',
        ]);
        app(ReportService::class)->suspend($target->id);
        app(ReportService::class)->reinstate($target->id);

        // 上書きではなく2件積まれる
        $this->assertSame(2, AuditLog::count());
    }

    // --- PII マスキング ---

    public function test_log_processor_masks_sensitive_context_keys(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'test',
            level: Level::Warning,
            message: 'ekyc failed',
            context: [
                'birthdate' => '1995-04-01',
                'location_hint' => '表町の居酒屋',
                'auth_token' => 'secret-token',
                'session_ref' => 'sess-1',
            ],
        );

        $masked = (new MaskPii)($record);

        $this->assertSame('***', $masked->context['birthdate']);
        $this->assertSame('***', $masked->context['location_hint']);
        $this->assertSame('***', $masked->context['auth_token']);
        // PII でないものは残る
        $this->assertSame('sess-1', $masked->context['session_ref']);
    }

    public function test_log_processor_masks_pii_inside_messages(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'test',
            level: Level::Info,
            message: 'contact guest@example.com or 090-1234-5678 born 1995-04-01',
            context: [],
        );

        $masked = (new MaskPii)($record);

        $this->assertStringNotContainsString('guest@example.com', $masked->message);
        $this->assertStringNotContainsString('090-1234-5678', $masked->message);
        $this->assertStringNotContainsString('1995-04-01', $masked->message);
    }

    // --- キュー ---

    public function test_scheduled_work_runs_through_queueable_jobs(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new ExpireStaleCallsJob);
        $this->assertInstanceOf(ShouldQueue::class, new ComputeRankingsJob);
    }

    public function test_production_env_example_lists_the_required_keys(): void
    {
        $env = (string) file_get_contents(base_path('.env.production.example'));

        foreach ([
            'QUEUE_CONNECTION=redis', 'CACHE_STORE=redis', 'SESSION_DRIVER=redis',
            'SESSION_SECURE_COOKIE=true', 'APP_DEBUG=false',
            'PATO_IDS_REGISTRATION', 'STRIPE_SECRET', 'EKYC_BASE_URL', 'VAPID_PUBLIC_KEY',
        ] as $key) {
            $this->assertStringContainsString($key, $env, "{$key} が本番雛形に無い");
        }
    }
}
