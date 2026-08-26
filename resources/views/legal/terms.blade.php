@extends('layouts.app')
@section('title', '利用規約｜pato岡山')

@section('content')
    <div class="card">
        <h2>利用規約</h2>
        <p class="sub">本サービスをご利用いただく前に必ずお読みください。</p>

        <h2 style="margin-top:16px">第1条（サービスの位置づけ）</h2>
        <p class="sub">
            本サービスは、エンタメスキルを提供したいキャストと、これを希望するゲストとの間での
            <b>エンタメスキル提供契約の成立の機会を提供する</b>ものです。
            当社は当該契約の当事者とはなりません。キャストの参加は本人の自由意思によるものであり、
            当社がキャストに参加を強制・指示することはありません。
        </p>

        <h2 style="margin-top:16px">第2条（利用資格）</h2>
        <p class="sub">
            満{{ config('pato.min_age') }}歳以上で、本人確認書類による年齢確認を完了した方に限りご利用いただけます。
            <b>{{ config('pato.min_age') }}歳未満の方はご利用いただけません。</b>
        </p>

        <h2 style="margin-top:16px">第3条（禁止行為）</h2>
        <div class="warn">
            以下の行為を禁止します。違反した場合、アカウントの停止・退会処分を行うことがあります。
            <ul style="margin:8px 0 0;padding-left:18px">
                <li>ホテル・自宅・鍵のかかる個室など<b>密室でのご利用</b></li>
                <li>現金の直接手渡し・当社を介さない直接取引</li>
                <li>連絡先の交換、外部サービスへの誘導、勧誘行為</li>
                <li><b>性的サービスの要求・提供</b>およびこれを想起させる言動</li>
                <li>直前・無断のキャンセル、相手方に不快感を与える行為</li>
            </ul>
        </div>

        <h2 style="margin-top:16px">第4条（利用場所）</h2>
        <p class="sub">
            レストラン・バー・その他公共の場所に限ります。密室でのご利用はできません。
        </p>

        <h2 style="margin-top:16px">第5条（ポイント）</h2>
        <p class="sub">
            ポイントは前払式の利用権であり、<b>理由のいかんを問わず払戻し・換金はできません</b>。
            有効期限は付与日から{{ config('pato.point.expiry_days') }}日です。退会時は失効します。
        </p>

        <h2 style="margin-top:16px">第6条（安全）</h2>
        <p class="sub">
            当社は監視・通報対応を行いますが、身の危険が差し迫っている場合は、まず110番など
            公的機関へご連絡ください。アプリのSOS機能は当社への連絡手段であり、緊急通報の代替ではありません。
        </p>

        <p class="hint" style="text-align:left;margin-top:20px">
            ※本規約は雛形です。公開前に弁護士のレビューを受けてください（docs/07_release_gate.md）。
        </p>
    </div>

    <a class="btn secondary" href="{{ url()->previous() }}">戻る</a>
@endsection
