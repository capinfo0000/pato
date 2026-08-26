@extends('layouts.app')
@section('title', 'ダッシュボード｜pato岡山 管理')

@section('content')
    <div class="card">
        <h2>ダッシュボード</h2>
        <div style="display:flex;gap:6px">
            @foreach (['this_month' => '今月', 'last_month' => '先月', 'all' => '全期間'] as $key => $label)
                <a href="{{ route('admin.dashboard', ['period' => $key]) }}"
                   class="badge {{ $period === $key ? 'now' : '' }}" style="padding:6px 12px">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    <div class="card">
        <h2>売上</h2>
        <div class="row"><span class="k">完了した呼び出し</span><span class="v">{{ number_format($summary['completed']) }}件</span></div>
        <div class="row"><span class="k">GMV（ゲスト支払）</span><span class="v">¥{{ number_format($summary['gmv_yen']) }}</span></div>
        <div class="row"><span class="k">キャスト報酬</span><span class="v">{{ number_format($summary['payout_points']) }}P</span></div>
        <div class="row"><span class="k">運営取り分</span><span class="v">{{ number_format($summary['take_points']) }}P</span></div>
        <div class="row">
            <span class="k">テイクレート実績</span>
            <span class="v">{{ $summary['take_rate_pct'] }}%</span>
        </div>
        <div class="row"><span class="k">平均単価</span><span class="v">{{ number_format($summary['avg_call_points']) }}P</span></div>
    </div>

    <div class="card">
        <h2>供給・需要</h2>
        <div class="row"><span class="k">稼働キャスト</span><span class="v">{{ $summary['active_casts'] }}人</span></div>
        <div class="row"><span class="k">今すぐ呼べる</span><span class="v standby">{{ $summary['standby_casts'] }}人</span></div>
        <div class="row"><span class="k">アクティブゲスト</span><span class="v">{{ $summary['guests'] }}人</span></div>
    </div>

    <div class="card">
        <h2>要対応</h2>
        <a class="row" href="{{ route('admin.reports.index') }}">
            <span class="k">未対応の通報</span><span class="v">{{ $summary['open_reports'] }}件 ›</span>
        </a>
        <a class="row" href="{{ route('admin.sos.index') }}"
           style="{{ $summary['open_sos'] > 0 ? 'border-color:#b3261e' : '' }}">
            <span class="k">未対応のSOS</span><span class="v">{{ $summary['open_sos'] }}件 ›</span>
        </a>
        <a class="row" href="{{ route('admin.screenings.index') }}">
            <span class="k">審査キュー</span><span class="v">›</span>
        </a>
        <a class="row" href="{{ route('admin.payouts.index') }}">
            <span class="k">未払いの精算</span><span class="v">{{ number_format($summary['pending_payout_points']) }}P ›</span>
        </a>
    </div>

    <div class="card">
        <h2>呼び出しの内訳</h2>
        @forelse ($breakdown as $status => $total)
            <div class="row"><span class="k">{{ $status }}</span><span class="v">{{ $total }}件</span></div>
        @empty
            <p class="muted">データがありません。</p>
        @endforelse
    </div>

    <a class="btn secondary" href="{{ route('admin.prices.index') }}">料金マスタを管理する</a>
@endsection
