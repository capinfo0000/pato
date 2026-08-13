@extends('layouts.app')
@section('title', $cast->display_name . '｜pato岡山')

@section('content')
    <div class="card">
        <div class="ph" style="height:160px;border-radius:12px;margin-bottom:12px"></div>
        <h2>{{ $cast->display_name }} @if ($cast->age)<span class="muted">{{ $cast->age }}歳</span>@endif</h2>
        <p class="sub">{{ $cast->bio }}</p>

        <div class="row"><span class="k">クラス</span><span class="v">{{ $cast->classTier?->name }}</span></div>
        <div class="row"><span class="k">活動エリア</span><span class="v">{{ $cast->homeArea?->name }}</span></div>
        <div class="row">
            <span class="k">在席</span>
            <span class="v">
                @if ($cast->in_session) 合流中
                @elseif ($cast->availability === 'now') 今すぐ可
                @elseif ($cast->availability === 'today') 本日可
                @else オフライン @endif
            </span>
        </div>
        @if ($pointsPer30min)
            <div class="row">
                <span class="k">30分あたり</span>
                <span class="v">{{ number_format($pointsPer30min) }}P</span>
            </div>
            <div class="row">
                <span class="k">指名で呼ぶ場合</span>
                <span class="v">+{{ $nominationBp / 100 }}%</span>
            </div>
        @endif
    </div>

    @if ($cast->kpi)
        <div class="card">
            <h2>評価</h2>
            <div class="row"><span class="k">延長率</span><span class="v">★{{ number_format($cast->kpi->extendRate(), 1) }}</span></div>
            <div class="row"><span class="k">サービスリピート率</span><span class="v">★{{ number_format($cast->kpi->repeatRate(), 1) }}</span></div>
            <div class="row"><span class="k">また会いたい率</span><span class="v">★{{ number_format($cast->kpi->remeetRate(), 1) }}</span></div>
            <div class="row"><span class="k">ファンポイント</span><span class="v">{{ number_format($cast->kpi->fan_points_total) }}</span></div>
        </div>
    @endif

    @if ($badgeCounts->isNotEmpty())
        <div class="card">
            <h2>ゲストから受け取ったバッジ</h2>
            @foreach ($badgeCounts as $name => $count)
                <div class="row"><span class="k">{{ $name }}</span><span class="v">×{{ $count }}</span></div>
            @endforeach
        </div>
    @endif

    @if ($cast->awards->isNotEmpty())
        <div class="card">
            <h2>獲得した称号</h2>
            @foreach ($cast->awards as $award)
                <div class="row"><span class="k">{{ $award->season }}</span><span class="v">{{ $award->title }}</span></div>
            @endforeach
        </div>
    @endif

    <a class="btn" href="{{ route('calls.create') }}">このクラスで呼ぶ</a>
    <div style="height:8px"></div>
    <a class="btn secondary" href="{{ route('casts.index') }}">検索に戻る</a>
@endsection
