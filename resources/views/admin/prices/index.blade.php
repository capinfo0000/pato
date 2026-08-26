@extends('layouts.app')
@section('title', '料金マスタ｜pato岡山 管理')

@section('content')
    <div class="card">
        <h2>エリアの提供可否</h2>
        <p class="sub">停止したエリアでは新規の呼び出しを作成できません。</p>
        @foreach ($areas as $area)
            <div class="row">
                <span class="v">{{ $area->name }}</span>
                <form method="POST" action="{{ route('admin.areas.toggle', $area) }}">@csrf
                    <button class="btn {{ $area->serviceable ? '' : 'secondary' }}"
                            style="width:auto;padding:8px 12px" type="submit">
                        {{ $area->serviceable ? '提供中' : '停止中' }}
                    </button>
                </form>
            </div>
        @endforeach
    </div>

    <div class="card">
        <h2>料金・テイクレート</h2>
        <p class="sub">bp は千分率です（4000 = 40%）。変更は以降の呼び出しから適用されます。</p>

        @foreach ($prices as $price)
            <form method="POST" action="{{ route('admin.prices.update', $price) }}" style="margin-bottom:16px">
                @csrf
                <label>{{ $price->area?->name }} / {{ $price->classTier?->name }}</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <span style="flex:1;min-width:120px">
                        <span class="k">30分単価(P)</span>
                        <input type="text" name="points_per_30min" value="{{ $price->points_per_30min }}">
                    </span>
                    <span style="flex:1;min-width:120px">
                        <span class="k">テイクレート(bp)</span>
                        <input type="text" name="take_rate_bp" value="{{ $price->take_rate_bp }}">
                    </span>
                    <span style="flex:1;min-width:120px">
                        <span class="k">指名加算(bp)</span>
                        <input type="text" name="nomination_surcharge_bp" value="{{ $price->nomination_surcharge_bp }}">
                    </span>
                    <span style="flex:1;min-width:120px">
                        <span class="k">深夜加算(bp)</span>
                        <input type="text" name="night_surcharge_bp" value="{{ $price->night_surcharge_bp }}">
                    </span>
                </div>
                <div style="height:8px"></div>
                <button class="btn secondary" type="submit">この行を更新</button>
            </form>
        @endforeach
    </div>

    <a class="btn secondary" href="{{ route('admin.dashboard') }}">ダッシュボードへ</a>
@endsection
