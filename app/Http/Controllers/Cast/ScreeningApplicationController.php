<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cast;

use App\Application\Cast\ScreeningService;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\CastProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * キャストの審査申込。承認されるまで募集への参加はできない。
 */
final class ScreeningApplicationController extends Controller
{
    public function show(ScreeningService $service): View
    {
        $profile = CastProfile::where('user_id', Auth::id())->first();

        return view('cast.apply', [
            'profile' => $profile,
            'areas' => Area::where('serviceable', true)->get(),
            'canReapply' => $profile !== null && $service->canReapply($profile),
            'reapplyMonths' => ScreeningService::REAPPLY_AFTER_MONTHS,
            'balance' => 0,
        ]);
    }

    public function store(Request $request, ScreeningService $service): RedirectResponse
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:30'],
            'home_area_id' => ['required', 'integer', 'exists:areas,id'],
            'age' => ['required', 'integer', 'min:18', 'max:99'],
            'bio' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $service->apply(
                Auth::user(),
                $validated['display_name'],
                (int) $validated['home_area_id'],
                (int) $validated['age'],
                $validated['bio'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage() === 'reapply_too_soon'
                ? '再申込は前回の審査から'.ScreeningService::REAPPLY_AFTER_MONTHS.'か月経過後に可能です。'
                : '申込できませんでした。');
        }

        return redirect()->route('cast.apply.show')
            ->with('status', '審査に申し込みました。結果をお待ちください。');
    }
}
