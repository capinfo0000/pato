<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Trust\SosService;
use App\Http\Controllers\Controller;
use App\Models\SosEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** 管理者のSOS対応。位置メモは管理者のみが閲覧できる。 */
final class SosController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'acknowledged', 'resolved'])],
        ]);
        $status = $filters['status'] ?? 'open';

        return view('admin.sos.index', [
            'events' => SosEvent::where('status', $status)
                ->with('user', 'call.area')
                ->latest()
                ->paginate(30),
            'status' => $status,
            'counts' => SosEvent::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'balance' => 0,
        ]);
    }

    public function acknowledge(SosEvent $sosEvent, SosService $service): RedirectResponse
    {
        return $this->run(fn () => $service->acknowledge($sosEvent, Auth::user()), '受信を確認しました。');
    }

    public function resolve(Request $request, SosEvent $sosEvent, SosService $service): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        return $this->run(
            fn () => $service->resolve($sosEvent, Auth::user(), $validated['note'] ?? null),
            '対応完了にしました。',
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
