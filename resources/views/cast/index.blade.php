@extends('layouts.app')
@section('title', 'キャストホーム｜pato岡山')

@section('content')
    <div class="card">
        <h2>{{ $cast->display_name }} さん</h2>
        <p class="sub">参加は任意です。応じたい募集にだけ参加表明してください。</p>

        <div class="row"><span class="k">クラス</span><span class="v">{{ $cast->classTier?->name }}</span></div>
        <div class="row"><span class="k">受取ポイント</span><span class="v">{{ number_format($earned) }}P</span></div>

        <form method="POST" action="{{ route('cast.availability') }}">
            @csrf
            <label>在席ステータス</label>
            <select name="availability" onchange="this.form.submit()">
                <option value="now" @selected($cast->availability === 'now')>今すぐ可</option>
                <option value="today" @selected($cast->availability === 'today')>本日可</option>
                <option value="offline" @selected($cast->availability === 'offline')>オフライン</option>
            </select>
        </form>
    </div>

    <div class="card">
        <h2>募集中の呼び出し</h2>
        @forelse ($openCalls as $call)
            <div class="row">
                <span>
                    <span class="v">{{ $call->start_at->format('n/j H:i') }}〜</span>
                    <span class="k" style="display:block">
                        {{ $call->area->name }} /
                        {{ intdiv($call->duration_min, 60) }}時間 / {{ $call->headcount }}名募集
                    </span>
                </span>
                <form method="POST" action="{{ route('cast.participate', $call) }}">@csrf
                    <button class="btn" style="width:auto;padding:8px 14px" type="submit">参加する</button>
                </form>
            </div>
        @empty
            <p class="muted">現在、条件に合う募集はありません。</p>
        @endforelse
    </div>

    @if ($myCalls->isNotEmpty())
        <div class="card">
            <h2>参加予定・合流中</h2>
            @foreach ($myCalls as $call)
                <div class="row">
                    <span class="v">{{ $call->start_at->format('n/j H:i') }} {{ $call->area->name }}</span>
                    <span class="k">{{ $call->status->value === 'in_progress' ? '合流中' : '成立' }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('logout') }}">@csrf
        <button class="btn secondary" type="submit">ログアウト</button>
    </form>
@endsection
