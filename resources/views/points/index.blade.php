@extends('layouts.app')
@section('title', 'ポイント｜pato岡山')

@section('content')
    <div class="card">
        <h2>ポイントチャージ</h2>
        <p class="sub">1P = ¥1.2 相当。有効期限は付与から{{ $expiryDays }}日です。</p>
        <div class="row"><span class="k">利用可能ポイント</span><span class="v">{{ number_format($balance) }}P</span></div>

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
