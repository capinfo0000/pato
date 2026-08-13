@extends('layouts.app')
@section('title', '探す｜pato岡山')

@section('content')
    <div class="card">
        <h2>キャストを探す</h2>
        <form method="GET" action="{{ route('casts.index') }}">
            <label>ニックネーム</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="名前で検索">

            <label>活動エリア</label>
            <select name="area_id">
                <option value="">すべて</option>
                @foreach ($areas as $area)
                    <option value="{{ $area->id }}" @selected(($filters['area_id'] ?? null) == $area->id)>{{ $area->name }}</option>
                @endforeach
            </select>

            <label>クラス</label>
            <select name="class">
                <option value="">すべて</option>
                @foreach (['premium' => 'プレミアム', 'vip' => 'VIP', 'royal_vip' => 'ロイヤルVIP'] as $code => $name)
                    <option value="{{ $code }}" @selected(($filters['class'] ?? null) === $code)>{{ $name }}</option>
                @endforeach
            </select>

            <label>在席</label>
            <select name="availability">
                <option value="">すべて</option>
                <option value="now" @selected(($filters['availability'] ?? null) === 'now')>今すぐ可</option>
                <option value="today" @selected(($filters['availability'] ?? null) === 'today')>本日可</option>
            </select>

            <label>年齢</label>
            <div style="display:flex;gap:8px">
                <input type="text" name="age_min" value="{{ $filters['age_min'] ?? '' }}" placeholder="20">
                <input type="text" name="age_max" value="{{ $filters['age_max'] ?? '' }}" placeholder="35">
            </div>

            <div style="height:8px"></div>
            <button class="btn" type="submit">この条件で検索する</button>
        </form>
    </div>

    <div class="card">
        <h2>検索結果（{{ $casts->total() }}人）</h2>
        @if ($casts->isEmpty())
            <p class="muted">条件に合うキャストが見つかりませんでした。</p>
        @else
            <div class="grid">
                @foreach ($casts as $cast)
                    <a class="cast" href="{{ route('casts.show', $cast) }}">
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
                            <div class="muted" style="font-size:11px">{{ $cast->homeArea?->name }}</div>
                        </div>
                    </a>
                @endforeach
            </div>
            <div style="margin-top:12px">{{ $casts->links() }}</div>
        @endif
    </div>
@endsection
