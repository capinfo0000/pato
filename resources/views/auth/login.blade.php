@extends('layouts.app')
@section('title', 'ログイン｜pato岡山')

@section('content')
    <div class="card">
        <h2>ログイン</h2>
        <p class="sub">pato岡山（仮）へようこそ。</p>

        @if ($errors->any())
            <div class="flash err">
                @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <label>メールアドレス</label>
            <input type="text" name="email" value="{{ old('email') }}" autofocus>

            <label>パスワード</label>
            <input type="text" name="password">

            <label style="margin-top:12px"><input type="checkbox" name="remember" value="1"> ログイン状態を保持</label>

            <div style="height:8px"></div>
            <button class="btn" type="submit">ログイン</button>
        </form>
    </div>

    <div class="card">
        <p class="sub" style="margin:0">
            デモ環境: <b>guest@example.com</b> / パスワード <b>password</b> でログインできます
            （<code>php artisan db:seed --class=DemoSeeder</code> 実行後）。
        </p>
    </div>
@endsection
