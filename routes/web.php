<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\PayoutController as AdminPayoutController;
use App\Http\Controllers\Admin\ScreeningController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\Cast\CastDashboardController;
use App\Http\Controllers\Cast\PayoutController as CastPayoutController;
use App\Http\Controllers\Cast\ScreeningApplicationController;
use App\Http\Controllers\CastDirectoryController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PointController;
use App\Http\Controllers\RankingController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check() ? redirect()->route('calls.home') : redirect()->route('login'));

// 認証・会員登録
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'register']);
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    // 本人確認（eKYC）
    Route::get('/verify', [VerificationController::class, 'show'])->name('verify.show');
    Route::post('/verify', [VerificationController::class, 'submit'])->name('verify.submit');

    // ランキング（ゲスト / キャスト）
    Route::get('/rankings', [RankingController::class, 'index'])->name('rankings');

    // メッセージ（ゲスト・キャスト共通）
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/{thread}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('/messages/{thread}', [MessageController::class, 'store'])->name('messages.store');
    Route::post('/messages/{thread}/toggle', [MessageController::class, 'toggle'])->name('messages.toggle');

    // ゲスト: 呼ぶ導線
    Route::middleware('role:guest')->group(function () {
        Route::get('/home', [CallController::class, 'home'])->name('calls.home');
        Route::get('/calls', [CallController::class, 'index'])->name('calls.index');
        Route::get('/calls/new', [CallController::class, 'create'])->name('calls.create');
        Route::post('/calls/confirm', [CallController::class, 'confirm'])->name('calls.confirm');
        Route::post('/calls', [CallController::class, 'store'])->name('calls.store');
        Route::get('/calls/{call}', [CallController::class, 'show'])->name('calls.show');
        Route::post('/calls/{call}/start', [CallController::class, 'start'])->name('calls.start');
        Route::post('/calls/{call}/complete', [CallController::class, 'complete'])->name('calls.complete');
        Route::post('/calls/{call}/cancel', [CallController::class, 'cancel'])->name('calls.cancel');
        Route::post('/calls/{call}/tip', [CallController::class, 'tip'])->name('calls.tip');
        Route::post('/calls/{call}/extend', [CallController::class, 'extend'])->name('calls.extend');
        Route::post('/calls/{call}/review', [CallController::class, 'review'])->name('calls.review');

        // 探す（絞り込み検索・キャスト詳細・選んで呼ぶ）
        Route::get('/casts', [CastDirectoryController::class, 'index'])->name('casts.index');
        Route::get('/casts/{castProfile}', [CastDirectoryController::class, 'show'])->name('casts.show');

        // ポイント購入
        Route::get('/points', [PointController::class, 'index'])->name('points.index');
        Route::post('/points/purchase', [PointController::class, 'purchase'])->name('points.purchase');
    });

    // キャスト
    Route::middleware('role:cast')->prefix('cast')->name('cast.')->group(function () {
        Route::get('/', [CastDashboardController::class, 'index'])->name('index');
        Route::post('/calls/{call}/participate', [CastDashboardController::class, 'participate'])->name('participate');
        Route::post('/availability', [CastDashboardController::class, 'availability'])->name('availability');
        Route::get('/apply', [ScreeningApplicationController::class, 'show'])->name('apply.show');
        Route::post('/apply', [ScreeningApplicationController::class, 'store'])->name('apply.store');
        Route::get('/payouts', [CastPayoutController::class, 'index'])->name('payouts');
        Route::post('/payouts/request', [CastPayoutController::class, 'request'])->name('payouts.request');
    });

    // 管理（審査）
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/screenings', [ScreeningController::class, 'index'])->name('screenings.index');
        Route::get('/screenings/{castProfile}', [ScreeningController::class, 'show'])->name('screenings.show');
        Route::post('/screenings/{castProfile}/advance', [ScreeningController::class, 'advance'])->name('screenings.advance');
        Route::post('/screenings/{castProfile}/approve', [ScreeningController::class, 'approve'])->name('screenings.approve');
        Route::post('/screenings/{castProfile}/reject', [ScreeningController::class, 'reject'])->name('screenings.reject');
        Route::get('/payouts', [AdminPayoutController::class, 'index'])->name('payouts.index');
        Route::post('/payouts/{payout}/approve', [AdminPayoutController::class, 'approve'])->name('payouts.approve');
        Route::post('/payouts/{payout}/paid', [AdminPayoutController::class, 'markPaid'])->name('payouts.paid');
        Route::post('/payouts/{payout}/reject', [AdminPayoutController::class, 'reject'])->name('payouts.reject');
    });
});
