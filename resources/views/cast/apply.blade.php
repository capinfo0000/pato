@extends('layouts.app')
@section('title', 'キャスト審査｜pato岡山')

@section('content')
    @if ($profile && $profile->screening_status !== 'rejected')
        <div class="card">
            <h2>審査状況</h2>
            <div class="row">
                <span class="k">現在の状態</span>
                <span class="v">
                    @switch($profile->screening_status)
                        @case('applied') 受付済み（写真審査待ち） @break
                        @case('photo_review') 写真審査中 @break
                        @case('interview') 面談審査中 @break
                        @case('approved') 承認済み @break
                    @endswitch
                </span>
            </div>
            @if ($profile->screening_status === 'approved')
                <a class="btn" href="{{ route('cast.index') }}">募集一覧へ</a>
            @else
                <p class="sub" style="margin-top:12px">
                    審査は写真審査と面談の二段階です。結果はメッセージでお知らせします。
                </p>
            @endif
        </div>
    @else
        <div class="card">
            <h2>キャスト審査に申し込む</h2>
            <p class="sub">写真審査と面談の二段階です。承認後に活動を開始できます。</p>

            @if ($profile && $profile->screening_status === 'rejected' && ! $canReapply)
                <div class="flash err">
                    再申込は前回の審査から{{ $reapplyMonths }}か月経過後に可能です。
                </div>
            @else
                @if ($errors->any())
                    <div class="flash err">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
                @endif

                <form method="POST" action="{{ route('cast.apply.store') }}">
                    @csrf
                    <label>活動名（ニックネーム）</label>
                    <input type="text" name="display_name" value="{{ old('display_name', $profile?->display_name) }}">

                    <label>活動エリア</label>
                    <select name="home_area_id">
                        @foreach ($areas as $area)
                            <option value="{{ $area->id }}">{{ $area->name }}</option>
                        @endforeach
                    </select>

                    <label>年齢（18歳以上）</label>
                    <input type="text" name="age" value="{{ old('age', $profile?->age) }}">

                    <label>ひとこと（任意）</label>
                    <textarea name="bio" rows="2">{{ old('bio', $profile?->bio) }}</textarea>

                    <div style="height:8px"></div>
                    <button class="btn" type="submit">審査に申し込む</button>
                </form>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('logout') }}">@csrf
        <button class="btn secondary" type="submit">ログアウト</button>
    </form>
@endsection
