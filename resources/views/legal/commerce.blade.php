@extends('layouts.app')
@section('title', '特定商取引法に基づく表示｜pato岡山')

@section('content')
    <div class="card">
        <h2>特定商取引法に基づく表示</h2>

        @if (empty($operator['name']))
            <div class="flash err">
                事業者情報が未設定です。公開前に <code>.env</code>（PATO_OPERATOR_*）を設定してください。
            </div>
        @endif

        <div class="row"><span class="k">販売業者</span><span class="v">{{ $operator['name'] ?: '（未設定）' }}</span></div>
        <div class="row"><span class="k">運営責任者</span><span class="v">{{ $operator['representative'] ?: '（未設定）' }}</span></div>
        <div class="row"><span class="k">所在地</span><span class="v">{{ $operator['address'] ?: '（未設定）' }}</span></div>
        <div class="row"><span class="k">連絡先</span><span class="v">{{ $operator['contact_email'] ?: '（未設定）' }}</span></div>
        <div class="row"><span class="k">お問い合わせ方法</span><span class="v">メールにて受付</span></div>

        <h2 style="margin-top:20px">料金・支払い</h2>
        <div class="row">
            <span class="k">ポイント価格</span>
            <span class="v">1有償ポイント = ¥{{ $point['yen_per_paid_point_x10'] / 10 }} 相当</span>
        </div>
        <div class="row"><span class="k">利用料金</span><span class="v">エリア・クラス・時間により変動（購入前に画面で提示）</span></div>
        <div class="row"><span class="k">支払方法</span><span class="v">クレジットカード / デビットカード</span></div>
        <div class="row"><span class="k">支払時期</span><span class="v">ポイント購入時に都度決済</span></div>
        <div class="row"><span class="k">提供時期</span><span class="v">決済完了後、直ちにご利用いただけます</span></div>

        <h2 style="margin-top:20px">返金・キャンセル</h2>
        <div class="warn">
            デジタルコンテンツの性質上、購入確定後のポイントの返品・交換・返金はお受けできません。<br>
            ポイントは<b>払戻し・換金ができません</b>。有効期限は付与日から<b>{{ $point['expiry_days'] }}日</b>です。<br>
            退会された場合、保有ポイントは失効します。<br>
            呼び出しのキャンセルは、成立前は無償、成立後は所定の条件に従います。
        </div>
    </div>

    <a class="btn secondary" href="{{ url()->previous() }}">戻る</a>
@endsection
