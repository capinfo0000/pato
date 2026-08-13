@extends('layouts.app')
@section('title', 'プライバシーポリシー｜pato岡山')

@section('content')
    <div class="card">
        <h2>プライバシーポリシー</h2>

        <h2 style="margin-top:16px">取得する情報</h2>
        <p class="sub">
            メールアドレス、ニックネーム、本人確認の結果（年齢確認の可否・生年月日）、
            利用履歴、決済に関する情報（カード番号は当社では保持せず、決済代行会社が扱います）。
        </p>

        <h2 style="margin-top:16px">利用目的</h2>
        <p class="sub">
            本人確認・年齢確認、マッチングの提供、料金の請求と精算、不正・違反行為の監視、
            お問い合わせ対応、サービス改善のため。
        </p>

        <h2 style="margin-top:16px">本人確認書類の取扱い</h2>
        <p class="sub">
            本人確認書類および顔画像は、本人確認事業者において取り扱われます。当社は確認結果と
            年齢要件の充足有無を保持し、生年月日は<b>暗号化して保管</b>します。他の利用者には公開されません。
        </p>

        <h2 style="margin-top:16px">第三者提供</h2>
        <p class="sub">
            法令に基づく場合、および人の生命・身体の安全のために必要な場合を除き、
            ご本人の同意なく第三者に提供しません。
        </p>

        <h2 style="margin-top:16px">開示・訂正・削除</h2>
        <p class="sub">
            ご本人からの請求に応じて、保有個人データの開示・訂正・利用停止・削除に対応します。
            お問い合わせ先は特定商取引法に基づく表示をご覧ください。
        </p>

        <p class="hint" style="text-align:left;margin-top:20px">
            ※本ポリシーは雛形です。公開前に弁護士のレビューを受けてください。
        </p>
    </div>

    <a class="btn secondary" href="{{ url()->previous() }}">戻る</a>
@endsection
