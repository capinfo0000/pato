@extends('layouts.app')
@section('title', 'ランキング｜pato岡山')

@section('content')
    <div class="card">
        <h2>ランキング</h2>
        <div style="display:flex;gap:8px;margin-bottom:10px">
            @foreach (['guest' => 'ゲスト', 'cast' => 'キャスト'] as $key => $label)
                <a class="btn {{ $subject === $key ? '' : 'secondary' }}"
                   href="{{ route('rankings', ['subject' => $key, 'period' => $period]) }}">{{ $label }}</a>
            @endforeach
        </div>
        <div style="display:flex;gap:6px;overflow-x:auto;padding-bottom:6px">
            @foreach (['yesterday'=>'昨日','last_week'=>'先週','last_month'=>'先月','this_month'=>'今月','year'=>'今年','all'=>'全期間'] as $key => $label)
                <a href="{{ route('rankings', ['subject' => $subject, 'period' => $key]) }}"
                   class="badge {{ $period === $key ? 'now' : '' }}" style="white-space:nowrap;padding:6px 10px">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    <div class="card">
        @forelse ($rows as $row)
            <div class="row">
                <span class="v">{{ $row->rank }}. {{ $names[$row->ref_id] ?? '（退会）' }}</span>
                <span class="k">{{ number_format($row->score) }}</span>
            </div>
        @empty
            <p class="muted">まだランキングデータがありません。</p>
        @endforelse

        <div class="row" style="background:#1f2430;color:#fff;border:none;margin-top:12px">
            <span class="v">{{ $myRow?->rank ? $myRow->rank . '位' : '圏外' }}</span>
            <span>あなた</span>
        </div>
    </div>
@endsection
