@extends('layouts.app')
@section('title', '精算｜pato岡山')

@section('content')
    <div class="card">
        <h2>受取・精算</h2>
        <div class="row"><span class="k">受取残高（未出金）</span><span class="v">{{ number_format($balance) }}P</span></div>
        <div class="row"><span class="k">出金申請できる報酬</span><span class="v">{{ number_format($pending) }}P</span></div>

        @if ($pending >= $minPoints)
            <form method="POST" action="{{ route('cast.payouts.request') }}">
                @csrf
                <label>振込タイミング</label>
                <select name="speed">
                    <option value="normal">通常（翌月末払い）</option>
                    <option value="express">早期振込（手数料 {{ number_format($expressFee) }}P）</option>
                </select>
                <div style="height:8px"></div>
                <button class="btn" type="submit">出金を申請する</button>
            </form>
        @else
            <p class="muted">出金申請は{{ number_format($minPoints) }}P以上から可能です。</p>
        @endif

        <p class="hint" style="text-align:left">
            ※報酬は個人事業主としての収入です。確定申告はご自身で行ってください。
        </p>
    </div>

    <div class="card">
        <h2>精算履歴</h2>
        @forelse ($payouts as $payout)
            <div class="row">
                <span>
                    <span class="v">{{ number_format($payout->amount_points) }}P</span>
                    <span class="k" style="display:block">
                        {{ $payout->speed === 'express' ? '早期振込' : '通常' }} ·
                        申請 {{ $payout->requested_at?->format('n/j') }}
                    </span>
                </span>
                <span class="k">
                    @switch($payout->status)
                        @case('requested') 申請中 @break
                        @case('approved') 承認済み @break
                        @case('paid') 送金済み @break
                        @case('rejected') 却下 @break
                        @default {{ $payout->status }}
                    @endswitch
                </span>
            </div>
        @empty
            <p class="muted">まだ精算履歴はありません。</p>
        @endforelse
    </div>

    <a class="btn secondary" href="{{ route('cast.index') }}">募集一覧へ</a>
@endsection
