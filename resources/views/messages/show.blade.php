@extends('layouts.app')
@section('title', 'メッセージ｜pato岡山')

@section('content')
    <div class="card">
        <h2>
            @if ($thread->isConcierge())
                patoコンシェルジュ
            @elseif ($thread->call)
                {{ $thread->call->area->name }}の呼び出し #{{ $thread->call_id }}
            @else
                スレッド
            @endif
        </h2>
        <p class="sub">
            参加: {{ $thread->participants->map(fn ($p) => $p->user?->nickname)->filter()->join('、') }}
        </p>

        @php
            $other = $thread->participants->firstWhere('user_id', '!=', auth()->id());
        @endphp
        @if ($other && ! $thread->isConcierge())
            <p class="sub" style="margin:0 0 8px">
                <a href="{{ route('reports.create', ['target_user_id' => $other->user_id, 'call_id' => $thread->call_id]) }}">
                    このユーザーを通報する
                </a>
            </p>
        @endif

        <div style="display:flex;gap:8px">
            <form method="POST" action="{{ route('messages.toggle', $thread) }}">@csrf
                <input type="hidden" name="field" value="is_favorite">
                <button class="badge {{ $participant->is_favorite ? 'now' : '' }}"
                        style="border:none;cursor:pointer;padding:6px 12px" type="submit">
                    {{ $participant->is_favorite ? 'お気に入り解除' : 'お気に入り' }}
                </button>
            </form>
            <form method="POST" action="{{ route('messages.toggle', $thread) }}">@csrf
                <input type="hidden" name="field" value="is_hidden">
                <button class="badge {{ $participant->is_hidden ? 'session' : '' }}"
                        style="border:none;cursor:pointer;padding:6px 12px" type="submit">
                    {{ $participant->is_hidden ? '非表示解除' : '非表示' }}
                </button>
            </form>
        </div>
    </div>

    <div class="card">
        @forelse ($thread->messages as $message)
            @php $mine = $message->sender_user_id === auth()->id(); @endphp
            <div style="margin-bottom:10px;text-align:{{ $mine ? 'right' : 'left' }}">
                <div class="muted" style="font-size:11px">{{ $message->sender?->nickname }}</div>
                <div style="display:inline-block;max-width:80%;padding:10px 12px;border-radius:12px;
                            background:{{ $mine ? '#f0810f' : '#f0f2f5' }};color:{{ $mine ? '#fff' : 'inherit' }};
                            text-align:left">
                    {{ $message->body }}
                </div>
                <div class="muted" style="font-size:10px">{{ $message->created_at?->format('n/j H:i') }}</div>
            </div>
        @empty
            <p class="muted">まだメッセージはありません。</p>
        @endforelse
    </div>

    <div class="card">
        <form method="POST" action="{{ route('messages.store', $thread) }}">
            @csrf
            <textarea name="body" rows="2" placeholder="メッセージを入力"></textarea>
            <div style="height:8px"></div>
            <button class="btn" type="submit">送信</button>
        </form>
        <p class="hint" style="text-align:left">
            連絡先の交換・外部サービスへの誘導・現金の直接取引・密室でのご利用は禁止です。
        </p>
    </div>

    <a class="btn secondary" href="{{ route('messages.index') }}">メッセージ一覧へ</a>
@endsection
