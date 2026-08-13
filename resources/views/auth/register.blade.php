@extends('layouts.app')
@section('title', '会員登録｜pato岡山')

@section('content')
    <div class="card">
        <h2>会員登録</h2>
        <p class="sub">ニックネームで活動できます。実名は公開されません。</p>

        @if ($errors->any())
            <div class="flash err">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
        @endif

        <form method="POST" action="{{ route('register') }}">
            @csrf
            <label>ニックネーム</label>
            <input type="text" name="nickname" value="{{ old('nickname') }}">

            <label>メールアドレス</label>
            <input type="text" name="email" value="{{ old('email') }}">

            <label>パスワード（8文字以上）</label>
            <input type="text" name="password">

            <label>ご利用区分</label>
            <select name="role">
                <option value="guest">ゲスト（キャストを呼ぶ）</option>
                <option value="cast">キャスト（呼ばれる）</option>
            </select>

            <label style="margin-top:12px">
                <input type="checkbox" name="agree" value="1"> 利用規約に同意し、18歳以上であることを確認しました
            </label>

            <div style="height:8px"></div>
            <button class="btn" type="submit">登録する</button>
        </form>
    </div>
    <a class="btn secondary" href="{{ route('login') }}">ログインへ戻る</a>
@endsection
