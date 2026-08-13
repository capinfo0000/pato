<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CallController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check() ? redirect()->route('calls.home') : redirect()->route('login'));

// 認証
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// 呼ぶ導線（要ログイン）
Route::middleware('auth')->group(function () {
    Route::get('/home', [CallController::class, 'home'])->name('calls.home');
    Route::get('/calls/new', [CallController::class, 'create'])->name('calls.create');
    Route::post('/calls/confirm', [CallController::class, 'confirm'])->name('calls.confirm');
    Route::post('/calls', [CallController::class, 'store'])->name('calls.store');
    Route::get('/calls/{call}', [CallController::class, 'show'])->name('calls.show');
});
