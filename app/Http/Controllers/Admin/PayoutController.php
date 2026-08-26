<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Payout\PayoutService;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** 管理者の精算承認・送金完了処理。 */
final class PayoutController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['requested', 'approved', 'paid', 'rejected'])],
        ]);
        $status = $filters['status'] ?? 'requested';

        return view('admin.payouts.index', [
            'payouts' => Payout::where('status', $status)
                ->with('wallet.user', 'items')
                ->latest()
                ->paginate(30),
            'status' => $status,
            'counts' => Payout::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'balance' => 0,
        ]);
    }

    public function approve(Payout $payout, PayoutService $service): RedirectResponse
    {
        return $this->run(fn () => $service->approve($payout, Auth::user()), '承認しました。');
    }

    public function markPaid(Payout $payout, PayoutService $service): RedirectResponse
    {
        return $this->run(fn () => $service->markPaid($payout), '送金完了として記録しました。');
    }

    public function reject(Payout $payout, PayoutService $service): RedirectResponse
    {
        return $this->run(fn () => $service->reject($payout), '却下しました。明細は未申請に戻ります。');
    }

    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return back()->with('error', match ($e->getMessage()) {
                'not_requested' => '申請中の精算のみ承認できます。',
                'not_approved' => '承認済みの精算のみ送金できます。',
                'cannot_reject' => 'この精算は却下できません。',
                default => '操作を完了できませんでした。',
            });
        }

        return back()->with('status', $success);
    }
}
