@extends('layouts.app')
@section('title', '確認｜pato岡山')

@section('content')
    <div class="card">
        <h2>この内容で呼び出します</h2>
        <p class="sub">お支払い（与信）は作成時に発生します。</p>

        <div class="row"><span class="k">エリア</span><span class="v">{{ $area->name }}</span></div>
        <div class="row"><span class="k">合流予定</span><span class="v">{{ $input['start_offset_min'] }}分後</span></div>
        <div class="row"><span class="k">設定時間</span><span class="v">{{ intdiv((int)$input['duration_min'],60) }}時間{{ (int)$input['duration_min']%60 ? '30分' : '' }}</span></div>
        <div class="row"><span class="k">場所種別</span><span class="v">{{ ['restaurant'=>'レストラン','bar'=>'バー','public'=>'公共の場'][$input['venue_kind']] }}</span></div>
        <div class="row">
            <span class="k">クラス / 人数</span>
            <span class="v">
                @foreach ($items as $it)
                    {{ ['premium'=>'プレミアム','vip'=>'VIP','royal_vip'=>'ロイヤルVIP'][$it['class']] }}{{ $it['headcount'] }}名{{ $it['nominated'] ? '(指名)' : '' }}@if(!$loop->last) / @endif
                @endforeach
            </span>
        </div>

        <div style="text-align:center;margin:16px 0 4px" class="muted">お支払い（与信）</div>
        <div style="text-align:center" class="amount">{{ number_format($quote->guestHoldPoints) }}P</div>
        <div style="text-align:center" class="muted">（¥{{ number_format($quote->guestYen()) }} 相当・1P=¥1.2）</div>

        @if ($balance < $quote->guestHoldPoints)
            <div class="flash err" style="margin-top:12px">
                残高が不足しています（保有 {{ number_format($balance) }}P）。チャージが必要です。
            </div>
        @endif

        @if ($firstCall)
            <div class="warn" style="margin-top:12px">
                <b>初めてのご利用にあたって</b><br>
                ・キャストへの現金の直接手渡し・直接取引は禁止です。<br>
                ・ホテル・自宅・鍵付き個室など密室でのご利用はできません。<br>
                ・連絡先の交換や外部サービスへの誘導は禁止です。<br>
                ・18歳未満はご利用いただけません。
            </div>
        @endif
    </div>

    <form method="POST" action="{{ route('calls.store') }}">
        @csrf
        <input type="hidden" name="area_id" value="{{ $input['area_id'] }}">
        <input type="hidden" name="start_offset_min" value="{{ $input['start_offset_min'] }}">
        <input type="hidden" name="duration_min" value="{{ $input['duration_min'] }}">
        <input type="hidden" name="venue_kind" value="{{ $input['venue_kind'] }}">
        <input type="hidden" name="is_night" value="{{ $input['is_night'] ?? 0 }}">
        <input type="hidden" name="note" value="{{ $input['note'] ?? '' }}">
        @foreach ($items as $i => $it)
            <input type="hidden" name="counts[{{ $it['class'] }}]" value="{{ $it['headcount'] }}">
            @if ($it['nominated'])<input type="hidden" name="nominate[{{ $it['class'] }}]" value="1">@endif
        @endforeach

        <button class="btn" type="submit" {{ $balance < $quote->guestHoldPoints ? 'disabled' : '' }}>
            この内容で呼び出す（{{ number_format($quote->guestHoldPoints) }}P）
        </button>
    </form>
    <div style="height:8px"></div>
    <a class="btn secondary" href="{{ route('calls.create') }}">条件を修正する</a>
@endsection
