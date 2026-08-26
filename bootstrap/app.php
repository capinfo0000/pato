<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        // 全レスポンスにセキュリティヘッダを付ける
        $middleware->append(SecurityHeaders::class);

        // プロキシ配下で https を正しく認識する（HSTS・secure cookie のため）
        $middleware->trustProxies(at: '*');

        // Webhook はブラウザのフォームではないので CSRF トークンを持てない。
        // 代わりに署名検証（StripeWebhookController）が門番になる。ここを増やすときは
        // 「認証も CSRF も無い口を増やす」ことになるので、必ず署名検証とセットにすること
        $middleware->validateCsrfTokens(except: [
            'webhooks/stripe',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
