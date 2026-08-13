@extends('layouts.app')
@section('title', '条件を入れて呼ぶ｜pato岡山')

@section('content')
    <div class="card">
        <h2>パトコールの条件</h2>
        <p class="sub">場所・時間・人数・クラスを指定してください。</p>

        @if ($errors->any())
            <div class="flash err">
                @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('calls.confirm') }}">
            @csrf

            <label>合流エリア</label>
            <select name="area_id" required>
                @foreach ($areas as $area)
                    <option value="{{ $area->id }}">{{ $area->name }}</option>
                @endforeach
            </select>

            <label>合流予定時刻</label>
            <select name="start_offset_min" required>
                <option value="30">30分後</option>
                <option value="60">1時間後</option>
                <option value="90">1時間30分後</option>
                <option value="120">2時間後</option>
            </select>

            <label>設定時間</label>
            <select name="duration_min" required>
                <option value="60">1時間</option>
                <option value="90">1時間30分</option>
                <option value="120">2時間</option>
                <option value="150">2時間30分</option>
                <option value="180">3時間</option>
            </select>

            <label>キャストクラス / 人数（複数指定でミックス）</label>
            @foreach (['premium' => 'プレミアム', 'vip' => 'VIP', 'royal_vip' => 'ロイヤルVIP'] as $code => $name)
                <div class="row">
                    <span class="v">{{ $name }}</span>
                    <span>
                        <label style="display:inline;margin:0 6px 0 0" class="muted">指名</label>
                        <input type="checkbox" name="nominate[{{ $code }}]" value="1">
                        <select name="counts[{{ $code }}]" style="width:auto;display:inline-block">
                            @for ($i = 0; $i <= 4; $i++)
                                <option value="{{ $i }}">{{ $i }}名</option>
                            @endfor
                        </select>
                    </span>
                </div>
            @endforeach

            <label>合流場所の種別（密室でのご利用は禁止です）</label>
            <select name="venue_kind" required>
                <option value="restaurant">レストラン</option>
                <option value="bar">バー</option>
                <option value="public">その他公共の場</option>
            </select>

            <label><input type="checkbox" name="is_night" value="1"> 深夜帯（加算あり）</label>

            <label>メモ（任意）</label>
            <textarea name="note" rows="2" placeholder="接待/歓談など、希望があればご記入ください"></textarea>

            <div style="height:8px"></div>
            <button class="btn" type="submit">確認へ進む</button>
            <p class="hint">※この段階ではお支払いは発生しません</p>
        </form>
    </div>
@endsection
