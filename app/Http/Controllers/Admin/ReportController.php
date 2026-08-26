<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Trust\ReportService;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** 管理者の通報対応キューと制裁。NG検知されたメッセージもここで確認する。 */
final class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'reviewing', 'actioned', 'dismissed'])],
        ]);
        $status = $filters['status'] ?? 'open';

        return view('admin.reports.index', [
            'reports' => Report::where('status', $status)
                ->with('reporter', 'target', 'call')
                ->latest()
                ->paginate(30),
            'status' => $status,
            'counts' => Report::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'reasons' => ReportService::REASONS,
            'flaggedMessages' => Message::where('flagged', true)
                ->with('sender', 'thread')
                ->latest('id')
                ->limit(20)
                ->get(),
            'balance' => 0,
        ]);
    }

    public function review(Report $report, ReportService $service): RedirectResponse
    {
        return $this->run(fn () => $service->startReview($report), '対応中にしました。');
    }

    public function action(Request $request, Report $report, ReportService $service): RedirectResponse
    {
        $suspend = $request->boolean('suspend');

        return $this->run(
            fn () => $service->action($report, $suspend),
            $suspend ? '対応済みにし、アカウントを停止しました。' : '対応済みにしました。',
        );
    }

    public function dismiss(Report $report, ReportService $service): RedirectResponse
    {
        return $this->run(fn () => $service->dismiss($report), '却下しました。');
    }

    public function reinstate(Report $report, ReportService $service): RedirectResponse
    {
        return $this->run(
            fn () => $service->reinstate($report->target_user_id),
            'アカウント停止を解除しました。',
        );
    }

    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (\DomainException) {
            return back()->with('error', 'この操作は現在の状態では実行できません。');
        }

        return back()->with('status', $success);
    }
}
