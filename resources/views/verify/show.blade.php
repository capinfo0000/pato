@extends('layouts.app')
@section('title', '本人確認｜pato岡山')

@section('content')
    <div class="card">
        <h2>本人確認</h2>
        <p class="sub">安全なご利用のため、公的身分証による年齢確認をお願いしています。</p>

        @if ($verification?->isVerified())
            <div class="flash ok">本人確認は完了しています。</div>
        @else
            <div class="warn" style="margin-bottom:12px">
                18歳未満の方はご利用いただけません。確認書類は暗号化して保管され、他の利用者には公開されません。
            </div>

            @if ($errors->any())
                <div class="flash err">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
            @endif

            <form method="POST" action="{{ route('verify.submit') }}">
                @csrf
                <label>生年月日</label>
                <input type="text" name="birthdate" placeholder="1995-04-01" value="{{ old('birthdate') }}">
                <p class="hint" style="text-align:left">※本番環境ではeKYCベンダの撮影フローに置き換わります</p>
                <div style="height:8px"></div>
                <button class="btn" type="submit">確認を送信する</button>
            </form>
        @endif
    </div>
@endsection
