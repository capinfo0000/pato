@extends('layouts.app')
@section('title', '精算管理｜pato岡山 管理')

@section('content')
    <div class="card">
        <h2>精算管理</h2>
        <div style="display:flex;gap:6px;overflow-x:auto;padding-bottom:6px">
            @foreach (['requested' => '申請中', 'approved' => '承認済', 'paid' => '送金済', 'rejected' => '却下'] as $key => $label)
                <a href="{{ route('admin.payouts.index', ['status' => $key]) }}"
                   class="badge {{ $status === $key ? 'now' : '' }}" style="white-space:nowrap;padding:6px 10px">
                    {{ $label }} {{ $counts[$key] ?? 0 }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="card">
        @forelse ($payouts as $payout)
            <div class="row" style="flex-wrap:wrap">
                <span>
                    <span class="v">{{ $payout->wallet?->user?->nickname }} / {{ number_format($payout->amount_points) }}P</span>
                    <span class="k" style="display:block">
                        {{ $payout->speed === 'express' ? '早期振込' : '通常' }} ·
                        明細{{ $payout->items->count() }}件 ·
                        申請 {{ $payout->requested_at?->format('n/j H:i') }}
                    </span>
                </span>
                <span style="display:flex;gap:6px;margin-top:6px">
                    @if ($payout->status === 'requested')
                        <form method="POST" action="{{ route('admin.payouts.approve', $payout) }}">@csrf
                            <button class="btn" style="width:auto;padding:8px 12px" type="submit">承認</button>
                        </form>
                    @elseif ($payout->status === 'approved')
                        <form method="POST" action="{{ route('admin.payouts.paid', $payout) }}">@csrf
                            <button class="btn" style="width:auto;padding:8px 12px" type="submit">送金完了</button>
                        </form>
                    @endif
                    @if (in_array($payout->status, ['requested', 'approved'], true))
                        <form method="POST" action="{{ route('admin.payouts.reject', $payout) }}">@csrf
                            <button class="btn secondary" style="width:auto;padding:8px 12px" type="submit">却下</button>
                        </form>
                    @endif
                </span>
            </div>
        @empty
            <p class="muted">この状態の精算はありません。</p>
        @endforelse
        <div style="margin-top:12px">{{ $payouts->links() }}</div>
    </div>
@endsection
