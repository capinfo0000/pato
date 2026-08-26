<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Trust\ReportService;
use App\Models\Call;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** 利用者からの通報。ゲスト・キャストのどちらからでも行える。 */
final class ReportController extends Controller
{
    public function create(Request $request): View
    {
        $validated = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'call_id' => ['nullable', 'integer', 'exists:calls,id'],
        ]);

        return view('reports.create', [
            'target' => User::findOrFail($validated['target_user_id']),
            'callId' => $validated['call_id'] ?? null,
            'reasons' => ReportService::REASONS,
            'balance' => 0,
        ]);
    }

    public function store(Request $request, ReportService $service): RedirectResponse
    {
        $validated = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'call_id' => ['nullable', 'integer', 'exists:calls,id'],
            'reason' => ['required', Rule::in(array_keys(ReportService::REASONS))],
            'detail' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $service->report(
                Auth::user(),
                User::findOrFail($validated['target_user_id']),
                $validated['reason'],
                $validated['detail'] ?? null,
                isset($validated['call_id']) ? Call::find($validated['call_id']) : null,
            );
        } catch (\DomainException $e) {
            return back()->with('error', match ($e->getMessage()) {
                'already_reported' => 'この件はすでに通報済みです。運営が確認しています。',
                'cannot_report_self' => '自分自身は通報できません。',
                default => '通報できませんでした。',
            });
        }

        return redirect()->to(Auth::user()->isCast() ? route('cast.index') : route('calls.home'))
            ->with('status', '通報を受け付けました。運営が確認します。');
    }
}
