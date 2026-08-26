<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PointWallet;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 会員登録。実名は保持せずニックネームで活動する（プライバシー既定）。
 * 登録後は本人確認(eKYC)が未完了のため、呼び出し作成・参加はできない。
 */
final class RegisterController extends Controller
{
    public function show(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nickname' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['guest', 'cast'])],
            'agree' => ['accepted'], // 利用規約・18歳以上の確認
        ]);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'status' => 'active',
                'nickname' => $validated['nickname'],
            ]);
            PointWallet::create(['user_id' => $user->id]);

            return $user;
        });

        Auth::login($user);

        return redirect()->route('verify.show')
            ->with('status', 'ご登録ありがとうございます。ご利用には本人確認が必要です。');
    }
}
