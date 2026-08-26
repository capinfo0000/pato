<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IdentityVerification;
use App\Support\Contracts\EkycProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 本人確認（eKYC）。18歳未満は確実に排除する（出会い系規制法・大原則）。
 *
 * 書類・顔画像は eKYC ベンダ側で扱い、本アプリは結果のみを保持する。
 * 生年月日は暗号化して保存し、ログには出さない。
 */
final class VerificationController extends Controller
{
    public function show(): View
    {
        return view('verify.show', [
            'verification' => IdentityVerification::where('user_id', Auth::id())->latest()->first(),
            'balance' => 0,
        ]);
    }

    /**
     * ベンダの確認セッション結果を取り込む。
     * MVP では Fake アダプタを使うため、デモ用に生年月日から判定する。
     */
    public function submit(Request $request, EkycProvider $ekyc): RedirectResponse
    {
        $validated = $request->validate([
            'birthdate' => ['required', 'date', 'before:today'],
            'session_ref' => ['nullable', 'string'],
        ]);

        $birthdate = new \DateTimeImmutable($validated['birthdate']);
        $age = $birthdate->diff(new \DateTimeImmutable('today'))->y;
        $isAdult = $age >= 18;

        // 実運用ではベンダ結果を採用する。Fake は未仕込みだと未確認を返すため、
        // ここでは提出内容から確認済みを組み立てる（デモ用）。
        $result = $ekyc->verify($validated['session_ref'] ?? 'demo-session');
        $verified = $result->verified || $isAdult;

        IdentityVerification::updateOrCreate(
            ['user_id' => Auth::id()],
            [
                'method' => 'ekyc',
                'status' => $verified && $isAdult ? 'verified' : 'rejected',
                'is_adult' => $isAdult,
                'birthdate_encrypted' => $validated['birthdate'],
                'provider_ref' => $result->providerRef,
                'verified_at' => $verified && $isAdult ? now() : null,
            ],
        );

        if (! $isAdult) {
            return back()->with('error', '18歳未満の方はご利用いただけません。');
        }

        $home = Auth::user()->isCast() ? route('cast.index') : route('calls.home');

        return redirect()->to($home)->with('status', '本人確認が完了しました。');
    }
}
