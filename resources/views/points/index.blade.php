@extends('layouts.app')
@section('title', 'ポイント｜pato岡山')

@section('content')
    <div class="card">
        <h2>ポイントチャージ</h2>
        <p class="sub">1P = ¥1.2 相当。有効期限は付与から{{ $expiryDays }}日です。</p>
        <div class="row"><span class="k">利用可能ポイント</span><span class="v">{{ number_format($balance) }}P</span></div>

        @if ($publishableKey === '')
            {{-- 決済キー未設定（開発・デモ）。Fake ゲートウェイで即時チャージする --}}
            @foreach ($products as $product)
                <form method="POST" action="{{ route('points.purchase') }}" style="margin-bottom:8px">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <div class="row" style="margin-bottom:0">
                        <span class="v">{{ number_format($product->paid_points) }}P</span>
                        <span>
                            <span class="muted" style="margin-right:8px">¥{{ number_format($product->price_yen) }}</span>
                            <button class="btn" style="width:auto;padding:8px 14px" type="submit">購入</button>
                        </span>
                    </div>
                </form>
            @endforeach
            <p class="hint">決済キーが未設定のため、デモ用の即時チャージで動作しています。</p>
        @else
            <form method="POST" action="{{ route('points.purchase') }}" id="purchase-form">
                @csrf
                {{-- Stripe.js が発行する PaymentMethod ID がここに入る。カード番号は入らない --}}
                <input type="hidden" name="payment_method_token" id="payment-method-token">

                @foreach ($products as $product)
                    <label class="row" style="margin-bottom:10px;cursor:pointer">
                        <span>
                            <input type="radio" name="product_id" value="{{ $product->id }}"
                                   style="width:auto;margin-right:8px" @checked($loop->first)>
                            <span class="v">{{ number_format($product->paid_points) }}P</span>
                        </span>
                        <span class="muted">¥{{ number_format($product->price_yen) }}</span>
                    </label>
                @endforeach

                <label for="card-element">カード情報</label>
                <div id="card-element"
                     style="padding:12px;border:1px solid var(--line);border-radius:10px;background:#fff"></div>
                <p class="flash err" id="card-error" style="display:none;margin-top:10px"></p>

                <button class="btn" type="submit" id="purchase-submit" style="margin-top:12px">購入する</button>
                <p class="hint">カード情報は当サービスのサーバーを経由せず、決済代行会社へ直接送信されます。</p>
            </form>

            {{-- 3Dセキュア認証を終えたあとの確定用。JS が自動で送信する --}}
            <form method="POST" action="{{ route('points.confirm') }}" id="confirm-form" style="display:none">@csrf</form>
        @endif

        <div class="warn" style="margin-top:12px">
            ポイントは払戻し・換金できません。退会された場合は失効します。
        </div>
    </div>

    <div class="card">
        <h2>ポイント履歴</h2>
        @forelse ($history as $tx)
            <div class="row">
                <span class="k">{{ $tx->created_at?->format('m/d H:i') }} {{ $tx->type->value }}</span>
                <span class="v">{{ $tx->type->settledSign() < 0 ? '-' : '' }}{{ number_format($tx->points) }}P</span>
            </div>
        @empty
            <p class="muted">まだ履歴がありません。</p>
        @endforelse
    </div>
@endsection

@if ($publishableKey !== '')
    @push('scripts')
        <script src="https://js.stripe.com/v3/"></script>
        <script>
            (function () {
                const stripe = Stripe(@json($publishableKey));
                const elements = stripe.elements();
                const card = elements.create('card', {
                    hidePostalCode: true,
                    style: { base: { fontSize: '15px', color: '#1f2430', fontFamily: 'system-ui, sans-serif' } },
                });
                card.mount('#card-element');

                const form = document.getElementById('purchase-form');
                const submit = document.getElementById('purchase-submit');
                const tokenInput = document.getElementById('payment-method-token');
                const errorBox = document.getElementById('card-error');

                function fail(message) {
                    errorBox.textContent = message;
                    errorBox.style.display = 'block';
                    submit.disabled = false;
                    submit.textContent = '購入する';
                }

                card.on('change', (event) => {
                    if (event.error) { fail(event.error.message); } else { errorBox.style.display = 'none'; }
                });

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    submit.disabled = true;
                    submit.textContent = '処理中…';
                    errorBox.style.display = 'none';

                    // カード情報はここで直接 Stripe へ送られる。フォームには載らない
                    const { paymentMethod, error } = await stripe.createPaymentMethod({ type: 'card', card });
                    if (error) { fail(error.message); return; }

                    tokenInput.value = paymentMethod.id;
                    form.submit();
                });

                @if (session('stripe_action'))
                    // 3Dセキュア（本人認証）が要求された。ブラウザで認証してからサーバで確定する
                    (async () => {
                        const { error } = await stripe.handleNextAction({
                            clientSecret: @json(session('stripe_action')['client_secret']),
                        });
                        if (error) { fail(error.message); return; }
                        document.getElementById('confirm-form').submit();
                    })();
                @endif
            })();
        </script>
    @endpush
@endif
