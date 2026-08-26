<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Cast\ScreeningService;
use App\Http\Controllers\Controller;
use App\Models\CastProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 管理者向けのキャスト審査キュー。写真審査 → 面談 → 承認/却下。
 *
 * 審査基準・通過率は内部指標。外部に数値を出す場合は根拠と時点を明示すること（docs/04 §6）。
 */
final class ScreeningController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['applied', 'photo_review', 'interview', 'approved', 'rejected'])],
        ]);
        $status = $filters['status'] ?? 'applied';

        return view('admin.screenings.index', [
            'profiles' => CastProfile::where('screening_status', $status)
                ->with('user', 'homeArea', 'classTier')
                ->latest()
                ->paginate(30),
            'status' => $status,
            'counts' => CastProfile::query()
                ->selectRaw('screening_status, COUNT(*) as total')
                ->groupBy('screening_status')
                ->pluck('total', 'screening_status'),
            'balance' => 0,
        ]);
    }

    public function show(CastProfile $castProfile): View
    {
        return view('admin.screenings.show', [
            'profile' => $castProfile->load('user', 'homeArea', 'classTier', 'screenings.reviewer'),
            'balance' => 0,
        ]);
    }

    public function advance(CastProfile $castProfile, Request $request, ScreeningService $service): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->run(
            fn () => $service->advance($castProfile, Auth::user(), $validated['note'] ?? null),
            $castProfile,
            '次の審査段階へ進めました。',
        );
    }

    public function approve(CastProfile $castProfile, Request $request, ScreeningService $service): RedirectResponse
    {
        $validated = $request->validate([
            'class' => ['required', Rule::in(['premium', 'vip', 'royal_vip'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->run(
            fn () => $service->approve($castProfile, Auth::user(), $validated['class'], $validated['note'] ?? null),
            $castProfile,
            '承認しました。クラスを付与し、稼働可能になりました。',
        );
    }

    public function reject(CastProfile $castProfile, Request $request, ScreeningService $service): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->run(
            fn () => $service->reject($castProfile, Auth::user(), $validated['note'] ?? null),
            $castProfile,
            '却下しました。',
        );
    }

    private function run(callable $action, CastProfile $profile, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return back()->with('error', match ($e->getMessage()) {
                'cannot_advance' => 'この段階からは進められません。',
                'already_approved' => 'すでに承認済みです。',
                default => '操作を完了できませんでした。',
            });
        }

        return redirect()->route('admin.screenings.show', $profile)->with('status', $success);
    }
}
