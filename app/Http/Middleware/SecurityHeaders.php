<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * セキュリティヘッダを付与する。
 *
 * 決済・本人確認を扱うため、ブラウザ側の防御を最大限効かせる:
 * - CSP: XSS でスクリプトを注入されても実行させない（決済画面でのカード情報窃取対策）
 * - HSTS: 平文 HTTP への降格を許さない（本番のみ。開発で付けると localhost が壊れる）
 * - X-Frame-Options: クリックジャッキング（気づかぬうちに「呼び出す」を押させる）を防ぐ
 * - Referrer-Policy: 呼び出しIDなどを外部サイトへ漏らさない
 * - Permissions-Policy: 使わない強力な API を明示的に切る
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->headers($request) as $name => $value) {
            $response->headers->set($name, $value, replace: false);
        }

        return $response;
    }

    /** @return array<string, string> */
    private function headers(Request $request): array
    {
        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(self), payment=(self), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Content-Security-Policy' => $this->csp(),
        ];

        // HSTS は HTTPS のときだけ（開発の平文アクセスを壊さない）
        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        return $headers;
    }

    /**
     * CSP。現状はインラインの style/script を使っているため 'unsafe-inline' が要る。
     * 決済フォームを埋め込む際は Stripe のドメインをここに追加する。
     *
     * TODO: nonce 方式に移行して 'unsafe-inline' を外す（docs/08_security.md）。
     */
    private function csp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://js.stripe.com",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            // r.stripe.com は Stripe.js のエラー報告先。塞ぐと決済のデバッグができなくなる
            "connect-src 'self' https://api.stripe.com https://js.stripe.com https://r.stripe.com",
            'frame-src https://js.stripe.com https://hooks.stripe.com',
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
            'upgrade-insecure-requests',
        ]);
    }
}
