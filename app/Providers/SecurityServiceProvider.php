<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * セキュリティ関連の起動設定。
 *
 * レート制限の考え方:
 * - 金銭が動く操作（ポイント購入・呼び出し作成）は最も厳しく
 * - 認証はブルートフォース対策で IP + メールの組で絞る
 * - SOS は「緊急時に押せない」方が危険なので、通報より緩くする
 */
final class SecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 本番は HTTPS を強制（リバースプロキシ配下でも生成URLを https に）
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        $this->registerRateLimiters();
    }

    private function registerRateLimiters(): void
    {
        // ログイン: IP とメールの両方で絞る（総当たりとアカウント狙い撃ちの両方に効かせる）
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perMinute(5)->by((string) $request->input('email')),
        ]);

        // 会員登録: 大量アカウント作成を抑える
        RateLimiter::for('register', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        // 決済: カードテスト（盗難カードの有効性確認）に使われないよう最も厳しく
        RateLimiter::for('payment', fn (Request $request) => [
            Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()),
            Limit::perDay(50)->by($request->user()?->id ?: $request->ip()),
        ]);

        // 呼び出し作成・延長・おひねり: 与信が絡むので絞る
        RateLimiter::for('money', fn (Request $request) => Limit::perMinute(20)
            ->by($request->user()?->id ?: $request->ip()));

        // 通報: 嫌がらせ通報の連投を抑える
        RateLimiter::for('report', fn (Request $request) => Limit::perHour(20)
            ->by($request->user()?->id ?: $request->ip()));

        // SOS: 押せないことの方が危険なので緩め（連打はサービス側でまとめている）
        RateLimiter::for('sos', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));

        // 本人確認: 生年月日の総当たりを防ぐ
        RateLimiter::for('verify', fn (Request $request) => Limit::perHour(10)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
