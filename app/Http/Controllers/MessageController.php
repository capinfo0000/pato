<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Messaging\PostMessageService;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * メッセージ一覧とスレッド。呼び出し単位のグループチャットとコンシェルジュ。
 */
final class MessageController extends Controller
{
    public function index(Request $request, PostMessageService $service): View
    {
        $filters = $request->validate([
            'filter' => ['nullable', 'in:all,favorite,hidden'],
            'q' => ['nullable', 'string', 'max:50'],
        ]);
        $filter = $filters['filter'] ?? 'all';

        // 初回アクセスでコンシェルジュスレッドを用意する
        $service->ensureConciergeThread(Auth::user());

        $threads = Thread::query()
            ->whereHas('participants', fn ($q) => $q->where('user_id', Auth::id()))
            ->with([
                'call.area',
                'messages' => fn ($q) => $q->latest('id')->limit(1),
                'participants.user',
            ])
            ->when($filter === 'favorite', fn ($q) => $q->whereHas(
                'participants',
                fn ($p) => $p->where('user_id', Auth::id())->where('is_favorite', true),
            ))
            ->when($filter === 'hidden', fn ($q) => $q->whereHas(
                'participants',
                fn ($p) => $p->where('user_id', Auth::id())->where('is_hidden', true),
            ))
            ->when($filter === 'all', fn ($q) => $q->whereDoesntHave(
                'participants',
                fn ($p) => $p->where('user_id', Auth::id())->where('is_hidden', true),
            ))
            ->orderByDesc('last_message_at')
            ->get();

        // ニックネーム検索（相手の表示名で絞り込む）
        if (! empty($filters['q'])) {
            $needle = $filters['q'];
            $threads = $threads->filter(function (Thread $t) use ($needle) {
                return $t->participants
                    ->where('user_id', '!=', Auth::id())
                    ->contains(fn ($p) => str_contains((string) $p->user?->nickname, $needle));
            });
        }

        return view('messages.index', [
            'threads' => $threads,
            'filter' => $filter,
            'q' => $filters['q'] ?? '',
            'balance' => 0,
        ]);
    }

    public function show(Thread $thread): View
    {
        $this->authorizeParticipant($thread);

        $participant = $this->participant($thread);
        $participant->update(['last_read_at' => now()]);

        return view('messages.show', [
            'thread' => $thread->load('call.area', 'messages.sender', 'participants.user'),
            'participant' => $participant,
            'balance' => 0,
        ]);
    }

    public function store(Request $request, Thread $thread, PostMessageService $service): RedirectResponse
    {
        $this->authorizeParticipant($thread);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $result = $service->post($thread, Auth::user(), $validated['body']);

        $redirect = redirect()->route('messages.show', $thread);

        if ($result['warnings'] !== []) {
            return $redirect->with('error', $this->warningText($result['warnings']));
        }

        return $redirect;
    }

    /** お気に入り / 非表示の切替。 */
    public function toggle(Request $request, Thread $thread): RedirectResponse
    {
        $this->authorizeParticipant($thread);

        $validated = $request->validate([
            'field' => ['required', 'in:is_favorite,is_hidden'],
        ]);

        $participant = $this->participant($thread);
        $participant->update([$validated['field'] => ! $participant->{$validated['field']}]);

        return back()->with('status', '更新しました。');
    }

    private function authorizeParticipant(Thread $thread): void
    {
        abort_unless(
            $thread->participants()->where('user_id', Auth::id())->exists(),
            403,
        );
    }

    private function participant(Thread $thread): ThreadParticipant
    {
        return ThreadParticipant::firstOrCreate([
            'thread_id' => $thread->id,
            'user_id' => Auth::id(),
        ]);
    }

    /** @param  list<string>  $warnings */
    private function warningText(array $warnings): string
    {
        $labels = [
            'cash_direct' => '現金の直接手渡し・直接取引',
            'private_room' => 'ホテル・自宅など密室でのご利用',
            'sexual' => '性的なやり取り',
        ];
        $names = array_map(static fn (string $w) => $labels[$w] ?? $w, $warnings);

        return implode('、', $names).'は禁止されています。ご注意ください。';
    }
}
