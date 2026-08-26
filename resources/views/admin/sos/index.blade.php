@extends('layouts.app')
@section('title', 'SOS対応｜pato岡山 管理')

@section('content')
    <div class="card">
        <h2>SOS対応</h2>
        <div style="display:flex;gap:6px;overflow-x:auto;padding-bottom:6px">
            @foreach (['open' => '未対応', 'acknowledged' => '確認済', 'resolved' => '対応完了'] as $key => $label)
                <a href="{{ route('admin.sos.index', ['status' => $key]) }}"
                   class="badge {{ $status === $key ? 'now' : '' }}" style="white-space:nowrap;padding:6px 10px">
                    {{ $label }} {{ $counts[$key] ?? 0 }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="card">
        @forelse ($events as $event)
            <div class="row" style="flex-wrap:wrap;border-color:{{ $event->status === 'open' ? '#b3261e' : '' }}">
                <span style="width:100%">
                    <span class="v">{{ $event->user?->nickname }}（{{ $event->user?->role }}）</span>
                    <span class="k" style="display:block">
                        {{ $event->created_at?->format('n/j H:i') }}
                        @if ($event->call) / {{ $event->call->area?->name }} 呼び出し #{{ $event->call_id }} @endif
                    </span>
                    @if ($event->note)<span class="k" style="display:block">状況: {{ $event->note }}</span>@endif
                    @if ($event->location_hint)
                        <span class="k" style="display:block">現在地: {{ $event->location_hint }}</span>
                    @endif
                </span>
                <span style="display:flex;gap:6px;margin-top:8px">
                    @if ($event->status === 'open')
                        <form method="POST" action="{{ route('admin.sos.ack', $event) }}">@csrf
                            <button class="btn" style="width:auto;padding:8px 12px" type="submit">受信確認</button>
                        </form>
                    @endif
                    @if (in_array($event->status, ['open', 'acknowledged'], true))
                        <form method="POST" action="{{ route('admin.sos.resolve', $event) }}">@csrf
                            <button class="btn secondary" style="width:auto;padding:8px 12px" type="submit">対応完了</button>
                        </form>
                    @endif
                </span>
            </div>
        @empty
            <p class="muted">この状態のSOSはありません。</p>
        @endforelse
        <div style="margin-top:12px">{{ $events->links() }}</div>
    </div>
@endsection
