@extends('layouts.app')
@section('title', '呼び出し｜pato岡山')

@section('content')
    <div class="card">
        <h2>
            @switch($call->status->value)
                @case('open') 成立を待っています @break
                @case('matched') キャストが決まりました @break
                @case('in_progress') 合流中 @break
                @case('completed') 完了しました @break
                @case('canceled') キャンセルしました @break
                @case('expired') 成立しませんでした @break
            @endswitch
        </h2>
        <p class="sub">呼び出しID #{{ $call->id }}</p>

        <div class="row"><span class="k">エリア</span><span class="v">{{ $call->area->name }}</span></div>
        <div class="row"><span class="k">合流予定</span><span class="v">{{ $call->start_at->format('H:i') }}</span></div>
        <div class="row"><span class="k">設定時間</span><span class="v">{{ intdiv($call->duration_min, 60) }}時間{{ $call->duration_min % 60 ? '30分' : '' }}</span></div>
        <div class="row"><span class="k">募集人数</span><span class="v">{{ $call->headcount }}名</span></div>
        <div class="row">
            <span class="k">クラス</span>
            <span class="v">
                @foreach ($call->lineItems as $li)
                    {{ $li->classTier?->name }}{{ $li->headcount }}名@if (! $loop->last) / @endif
                @endforeach
            </span>
        </div>
        <div class="row"><span class="k">与信ポイント</span><span class="v">{{ number_format($call->hold_points) }}P</span></div>
    </div>

    @if ($call->participants->isNotEmpty())
        <div class="card">
            <h2>参加キャスト（{{ $call->participants->count() }}名）</h2>
            @foreach ($call->participants as $p)
                <div class="row">
                    <span class="v">{{ $p->castProfile?->display_name }}</span>
                    <span class="k">
                        {{ $p->castProfile?->classTier?->name }}
                        @if ($p->tip_points > 0) · おひねり{{ number_format($p->tip_points) }}P @endif
                    </span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- 状態に応じた操作 --}}
    @if ($call->status->value === 'matched')
        <form method="POST" action="{{ route('calls.start', $call) }}">@csrf
            <button class="btn" type="submit">合流を開始する</button>
        </form>
        <div style="height:8px"></div>
    @endif

    @if ($call->status->value === 'in_progress')
        <form method="POST" action="{{ route('calls.complete', $call) }}">@csrf
            <button class="btn" type="submit">終了して支払いを確定する</button>
        </form>
        <div style="height:8px"></div>

        <div class="card">
            <h2>おひねりを送る</h2>
            <p class="sub">{{ number_format($minTip) }}P以上。参加キャストへ均等に配分されます。</p>
            <form method="POST" action="{{ route('calls.tip', $call) }}">@csrf
                <input type="text" name="points" value="{{ $minTip }}">
                <div style="height:8px"></div>
                <button class="btn secondary" type="submit">送る</button>
            </form>
        </div>
    @endif

    @if (in_array($call->status->value, ['open', 'matched'], true))
        <form method="POST" action="{{ route('calls.cancel', $call) }}"
              onsubmit="return confirm('この呼び出しをキャンセルしますか？');">@csrf
            <button class="btn secondary" type="submit">キャンセルする</button>
        </form>
        <div style="height:8px"></div>
    @endif

    <a class="btn secondary" href="{{ route('calls.home') }}">ホームに戻る</a>
@endsection
