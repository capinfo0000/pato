<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PointProduct;
use App\Models\User;
use Database\Seeders\OkayamaMasterSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class WebSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    // --- セキュリティヘッダ ---

    public function test_security_headers_are_present(): void
    {
        $response = $this->get(route('login'));

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }

    public function test_csp_blocks_framing_and_foreign_form_posts(): void
    {
        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');

        // クリックジャッキング防止
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        // フォームの送信先を自サイトに限定（フィッシング的な差し替えを防ぐ）
        $this->assertStringContainsString("form-action 'self'", $csp);
        // 決済は Stripe のみ許可
        $this->assertStringContainsString('https://js.stripe.com', $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_hsts_only_on_https(): void
    {
        // 平文アクセスでは付けない（開発を壊さない）
        $this->get(route('login'))->assertHeaderMissing('Strict-Transport-Security');

        // HTTPS では付く
        $this->get('https://localhost'.route('login', absolute: false))
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
    }

    // --- レート制限 ---

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), ['email' => 'x@example.com', 'password' => 'wrong']);
        }

        // 6回目は 429
        $this->post(route('login'), ['email' => 'x@example.com', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_payment_is_rate_limited_more_strictly(): void
    {
        $this->seed(OkayamaMasterSeeder::class);
        $user = User::create([
            'email' => 'p@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'かいもの',
        ]);
        $product = PointProduct::first();

        // カードテスト対策で5回/分
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post(route('points.purchase'), ['product_id' => $product->id]);
        }

        $this->actingAs($user)->post(route('points.purchase'), ['product_id' => $product->id])
            ->assertStatus(429);
    }

    public function test_sos_is_more_permissive_than_reports(): void
    {
        // 緊急連絡が押せない方が危険なので、通報より上限を緩くしている
        $user = User::create([
            'email' => 's@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'ゲスト',
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->post(route('sos.store'), [])->assertRedirect();
        }
    }

    // --- 認証・認可 ---

    public function test_csrf_protection_is_enabled_for_state_changing_requests(): void
    {
        // withoutMiddleware を使わず、CSRF ミドルウェアが有効なことを確認する
        $this->assertContains(
            ValidateCsrfToken::class,
            app(Kernel::class)->getMiddlewareGroups()['web'],
        );
    }

    public function test_passwords_are_hashed_not_stored_in_plain_text(): void
    {
        $user = User::create([
            'email' => 'h@example.com', 'password' => 'plain-text-password',
            'role' => 'guest', 'nickname' => 'ゲスト',
        ]);

        $stored = (string) $user->fresh()->password;
        $this->assertNotSame('plain-text-password', $stored);
        $this->assertTrue(password_verify('plain-text-password', $stored));
    }

    public function test_password_is_hidden_from_serialization(): void
    {
        $user = User::create([
            'email' => 'h2@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'ゲスト',
        ]);

        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }

    public function test_session_cookie_is_http_only(): void
    {
        // JS から読めない（XSS でセッションを盗まれない）
        $this->assertTrue((bool) config('session.http_only'));
    }

    // --- 決済まわり ---

    public function test_no_card_data_is_ever_persisted(): void
    {
        // カード番号を保持するカラムが存在しないこと（PCI DSS のスコープを最小化する前提）
        $forbidden = ['card_number', 'card_no', 'pan', 'cvv', 'cvc', 'card_expiry'];

        // ドライバに依存しない方法で全テーブルを見る（本番は MySQL、開発は sqlite）
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            $columns = Schema::getColumnListing($name);
            foreach ($forbidden as $needle) {
                $this->assertNotContains(
                    $needle,
                    $columns,
                    "テーブル {$name} にカード情報らしきカラム {$needle} がある",
                );
            }
        }
    }
}
