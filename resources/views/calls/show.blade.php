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
        <div class="row"><span class="k">設定時間</span><span class="v">{{ intdiv($call->duration_min,60) }}時間{{ $call->duration_min%60 ? '30分' : '' }}</span></div>
        <div class="row"><span class="k">募集人数</span><span class="v">{{ $call->headcount }}名</span></div>
        <div class="row">
            <span class="k">クラス</span>
            <span class="v">
                @foreach ($call->lineItems as $li)
                    {{ $li->classTier?->name }}{{ $li->headcount }}名@if(!$loop->last) / @endif
                @endforeach
            </span>
        </div>
        <div class="row"><span class="k">与信ポイント</span><span class="v">{{ number_format($call->hold_points) }}P</span></div>
    </div>

    <a class="btn secondary" href="{{ route('calls.home') }}">ホームに戻る</a>
@endsection
