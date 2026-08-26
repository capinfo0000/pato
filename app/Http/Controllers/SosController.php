<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Trust\SosService;
use App\Models\Call;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** 利用者からのSOS発報。合流中に危険を感じたときの緊急連絡。 */
final class SosController extends Controller
{
    public function create(Request $request): View
    {
        $validated = $request->validate([
            'call_id' => ['nullable', 'integer', 'exists:calls,id'],
        ]);

        return view('sos.create', [
            'callId' => $validated['call_id'] ?? null,
            'balance' => 0,
        ]);
    }

    public function store(Request $request, SosService $service): RedirectResponse
    {
        $validated = $request->validate([
            'call_id' => ['nullable', 'integer', 'exists:calls,id'],
            'note' => ['nullable', 'string', 'max:300'],
            'location_hint' => ['nullable', 'string', 'max:200'],
        ]);

        $service->raise(
            Auth::user(),
            isset($validated['call_id']) ? Call::find($validated['call_id']) : null,
            $validated['note'] ?? null,
            $validated['location_hint'] ?? null,
        );

        return redirect()->to(Auth::user()->isCast() ? route('cast.index') : route('calls.home'))
            ->with('status', 'SOSを運営に通知しました。危険が差し迫っている場合は110番へご連絡ください。');
    }
}
