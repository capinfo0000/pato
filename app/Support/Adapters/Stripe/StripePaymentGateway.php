<?php

declare(strict_types=1);

namespace App\Support\Adapters\Stripe;

use App\Support\Contracts\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Stripe の PaymentIntents で課金する実アダプタ。
 *
 * 設計上の約束:
 * - カード番号は一切扱わない。フロントの Stripe.js が作った PaymentMethod ID のみ受け取る
 * - 冪等キーを Stripe にもそのまま渡し、リトライで二重課金しないようにする
 * - 失敗時は RuntimeException。呼び出し側（PurchasePointService）は台帳に何も書かない
 * - ログに card / PII を出さない（決済参照IDのみ）
 */
final class StripePaymentGateway implements PaymentGateway
{
    private const ENDPOINT = 'https://api.stripe.com/v1/payment_intents';

    public function __construct(
        private readonly string $secretKey,
        private readonly string $currency = 'jpy',
    ) {}

    public function charge(string $paymentMethodToken, int $amountYen, string $idempotencyKey): string
    {
        if ($amountYen <= 0) {
            throw new \InvalidArgumentException('amountYen は正');
        }

        $response = Http::asForm()
            ->withToken($this->secretKey)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post(self::ENDPOINT, [
                // JPY はゼロ小数通貨なので円をそのまま渡す
                'amount' => $amountYen,
                'currency' => $this->currency,
                'payment_method' => $paymentMethodToken,
                'confirm' => 'true',
                // 3DS 等でリダイレクトが必要になるケースはここでは扱わない（要求時はエラー）
                'automatic_payment_methods[enabled]' => 'true',
                'automatic_payment_methods[allow_redirects]' => 'never',
            ]);

        if ($response->failed()) {
            // カード情報や個人情報は出さず、Stripe のエラーコードのみ残す
            Log::warning('stripe.charge_failed', [
                'status' => $response->status(),
                'code' => $response->json('error.code'),
                'type' => $response->json('error.type'),
                'idempotency_key' => $idempotencyKey,
            ]);

            throw new \RuntimeException('payment_failed');
        }

        $status = (string) $response->json('status');
        if ($status !== 'succeeded') {
            Log::warning('stripe.charge_not_succeeded', [
                'status' => $status,
                'idempotency_key' => $idempotencyKey,
            ]);

            throw new \RuntimeException('payment_failed');
        }

        return (string) $response->json('id');
    }
}
