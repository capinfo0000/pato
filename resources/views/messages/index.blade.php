@extends('layouts.app')
@section('title', 'メッセージ｜pato岡山')

@section('content')
    <div class="card">
        <h2>メッセージ</h2>
        <div style="display:flex;gap:6px;margin-bottom:10px">
            @foreach (['all' => 'すべて', 'favorite' => 'お気に入り', 'hidden' => '非表示'] as $key => $label)
                <a href="{{ route('messages.index', ['filter' => $key]) }}"
                   class="badge {{ $filter === $key ? 'now' : '' }}" style="padding:6px 12px">{{ $label }}</a>
            @endforeach
        </div>
        <form method="GET" action="{{ route('messages.index') }}">
            <input type="hidden" name="filter" value="{{ $filter }}">
            <input type="text" name="q" value="{{ $q }}" placeholder="ニックネームで検索">
        </form>
    </div>

    <div class="card">
        @forelse ($threads as $thread)
            @php
                $others = $thread->participants->where('user_id', '!=', auth()->id());
                $title = $thread->isConcierge()
                    ? 'patoコンシェルジュ'
                    : ($thread->call ? $thread->call->area->name . 'の呼び出し #' . $thread->call_id : 'スレッド');
                $last = $thread->messages->first();
            @endphp
            <a class="row" href="{{ route('messages.show', $thread) }}">
                <span>
                    <span class="v">{{ $title }}</span>
                    <span class="k" style="display:block">
                        {{ $last ? \Illuminate\Support\Str::limit($last->body, 28) : 'メッセージはまだありません' }}
                    </span>
                </span>
                <span class="k">{{ $thread->last_message_at?->format('H:i') }}</span>
            </a>
        @empty
            <p class="muted">スレッドがありません。</p>
        @endforelse
    </div>
@endsection
