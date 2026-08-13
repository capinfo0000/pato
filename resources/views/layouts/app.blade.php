<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f0810f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="pato岡山">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icon.svg">
    <title>@yield('title', 'pato岡山（仮）')</title>
    <style>
        :root { --accent:#f0810f; --ink:#1f2430; --muted:#7b828f; --line:#e9ecf1; --bg:#f6f7f9; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:system-ui,-apple-system,"Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
               color:var(--ink); background:var(--bg); padding-bottom:72px; }
        a { color:inherit; text-decoration:none; }
        .topbar { display:flex; align-items:center; justify-content:space-between;
                  padding:14px 16px; background:#fff; border-bottom:1px solid var(--line); }
        .topbar .brand { font-weight:800; letter-spacing:.02em; }
        .pts { font-size:12px; color:var(--muted); }
        .pts b { color:var(--ink); font-size:14px; }
        .wrap { max-width:520px; margin:0 auto; padding:16px; }
        .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:16px; margin-bottom:14px; }
        .card h2 { margin:0 0 4px; font-size:18px; }
        .sub { color:var(--muted); font-size:13px; margin:0 0 12px; }
        .row { display:flex; align-items:center; justify-content:space-between; gap:8px;
               border:1px solid var(--line); border-radius:10px; padding:12px; margin-bottom:10px; }
        .row .k { color:var(--muted); font-size:12px; }
        .row .v { font-weight:700; }
        .btn { display:block; width:100%; text-align:center; background:var(--accent); color:#fff;
                border:none; border-radius:10px; padding:14px; font-size:16px; font-weight:700; cursor:pointer; }
        .btn.secondary { background:#fff; color:var(--accent); border:1px solid var(--accent); }
        .btn:disabled { opacity:.5; cursor:not-allowed; }
        .hint { text-align:center; color:var(--muted); font-size:12px; margin-top:8px; }
        .standby { color:var(--accent); font-weight:700; }
        .flash { border-radius:10px; padding:12px; margin-bottom:12px; font-size:14px; }
        .flash.ok { background:#eafaf0; color:#1a7f4b; }
        .flash.err { background:#fdecec; color:#b3261e; }
        .warn { background:#fff8ec; border:1px solid #f4d9a6; border-radius:10px; padding:12px; font-size:13px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:10px; }
        .cast { border:1px solid var(--line); border-radius:12px; overflow:hidden; background:#fff; }
        .cast .ph { height:96px; background:linear-gradient(135deg,#eef1f5,#e2e6ec); }
        .cast .meta { padding:8px; }
        .badge { display:inline-block; font-size:11px; background:#f0f2f5; color:#556; border-radius:6px; padding:2px 6px; }
        .badge.now { background:#e7f7ec; color:#1a7f4b; }
        .badge.session { background:#f0f2f5; color:#889; }
        label { font-size:13px; color:var(--muted); display:block; margin:10px 0 4px; }
        select, input[type=text], textarea { width:100%; padding:11px; border:1px solid var(--line);
                border-radius:10px; font-size:15px; background:#fff; }
        .nav { position:fixed; left:0; right:0; bottom:0; background:#fff; border-top:1px solid var(--line);
               display:flex; }
        .nav a { flex:1; text-align:center; padding:10px 0; font-size:11px; color:var(--muted); }
        .nav a.active { color:var(--accent); font-weight:700; }
        .amount { font-size:26px; font-weight:800; }
        .muted { color:var(--muted); }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="brand">pato<span style="color:var(--accent)">岡山</span></div>
        @auth
            <div class="pts">保有ポイント <b>{{ number_format($balance ?? 0) }}P</b></div>
        @endauth
    </div>

    <main class="wrap">
        @if (session('status'))
            <div class="flash ok">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="flash err">{{ session('error') }}</div>
        @endif
        @yield('content')
    </main>

    @auth
        @if (auth()->user()->isCast())
            <nav class="nav">
                <a href="{{ route('cast.index') }}" class="{{ request()->routeIs('cast.*') ? 'active' : '' }}">募集</a>
                <a href="{{ route('messages.index') }}" class="{{ request()->routeIs('messages.*') ? 'active' : '' }}">メッセージ</a>
                <a href="{{ route('rankings') }}" class="{{ request()->routeIs('rankings') ? 'active' : '' }}">ランキング</a>
                <a href="{{ route('verify.show') }}" class="{{ request()->routeIs('verify.*') ? 'active' : '' }}">本人確認</a>
            </nav>
        @else
            <nav class="nav">
                <a href="{{ route('casts.index') }}" class="{{ request()->routeIs('casts.*') ? 'active' : '' }}">探す</a>
                <a href="{{ route('messages.index') }}" class="{{ request()->routeIs('messages.*') ? 'active' : '' }}">メッセージ</a>
                <a href="{{ route('calls.home') }}" class="{{ request()->routeIs('calls.home') ? 'active' : '' }}">呼ぶ</a>
                <a href="{{ route('rankings') }}" class="{{ request()->routeIs('rankings') ? 'active' : '' }}">ランキング</a>
                <a href="{{ route('points.index') }}" class="{{ request()->routeIs('points.*') ? 'active' : '' }}">ポイント</a>
            </nav>
        @endif
    @endauth
    <script>
        // Service Worker 登録（PWA: インストール可能化とオフラインシェル）
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').catch(() => {});
            });
        }
    </script>
</body>
</html>
