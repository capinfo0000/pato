@extends('layouts.app')
@section('title', '呼ぶ｜pato岡山')

@section('content')
    <div class="card">
        <h2>今すぐ呼ぶ</h2>
        <p class="sub">条件を入れて、最短で合流できるキャストを呼び出します。</p>

        @if ($area)
            <div class="row"><span class="k">合流エリア</span><span class="v">{{ $area->name }}</span></div>
            <div class="row">
                <span class="k">待機キャスト数</span>
                <span class="v"><span class="standby">{{ $standbyCount }}</span> 人</span>
            </div>
            <a class="btn" href="{{ route('calls.create') }}">条件を入れて呼ぶ</a>
            <p class="hint">※この段階ではお支払いは発生しません</p>
        @else
            <p class="muted">現在ご利用可能なエリアがありません。</p>
        @endif
    </div>

    <div class="card">
        <h2>今日会えるキャスト</h2>
        <p class="sub">本日対応可能なキャストです。</p>
        @if ($todaysCasts->isEmpty())
            <p class="muted">現在オンラインのキャストがいません。</p>
        @else
            <div class="grid">
                @foreach ($todaysCasts as $cast)
                    <div class="cast">
                        <div class="ph"></div>
                        <div class="meta">
                            <div style="font-weight:700;font-size:13px">{{ $cast->display_name }}
                                @if ($cast->age)<span class="muted">{{ $cast->age }}歳</span>@endif
                            </div>
                            <div style="margin:4px 0">
                                <span class="badge">{{ $cast->classTier?->name }}</span>
                                @if ($cast->in_session)
                                    <span class="badge session">合流中</span>
                                @elseif ($cast->availability === 'now')
                                    <span class="badge now">今すぐ可</span>
                                @endif
                            </div>
                            <div class="muted" style="font-size:11px">{{ $cast->bio }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <form method="POST" action="{{ route('logout') }}">@csrf
        <button class="btn secondary" type="submit">ログアウト</button>
    </form>
@endsection
