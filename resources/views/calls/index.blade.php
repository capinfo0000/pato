@extends('layouts.app')
@section('title', '注文履歴｜pato岡山')

@section('content')
    <div class="card">
        <h2>注文履歴</h2>
        @forelse ($calls as $call)
            <a class="row" href="{{ route('calls.show', $call) }}">
                <span>
                    <span class="v">{{ $call->start_at->format('n/j H:i') }}</span>
                    <span class="k" style="display:block">{{ $call->area->name }} / {{ $call->headcount }}名</span>
                </span>
                <span class="k">{{ $call->status->value }} · {{ number_format($call->hold_points) }}P</span>
            </a>
        @empty
            <p class="muted">まだ履歴がありません。</p>
        @endforelse
        <div style="margin-top:12px">{{ $calls->links() }}</div>
    </div>
@endsection
