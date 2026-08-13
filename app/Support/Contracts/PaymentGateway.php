<?php

declare(strict_types=1);

namespace App\Support\Contracts;

/**
 * 決済PSPの抽象。カード情報は自社保持せず、PSP のトークンで扱う。
 * 実装は Stripe 等の Adapter、テストは Fake。
 */
interface PaymentGateway
{
    /**
     * ポイント購入の課金。
     *
     * 3Dセキュアが要求された場合は例外ではなく `requires_action` を返す。
     * 呼び出し側はブラウザで認証させたのち `confirm()` で確定する。
     *
     * @param  string  $paymentMethodToken  PSP 発行のトークン（カード番号は扱わない）
     * @param  int  $amountYen  請求額（円・整数）
     *
     * @throws \RuntimeException 決済失敗（カード拒否・通信エラー等）
     */
    public function charge(string $paymentMethodToken, int $amountYen, string $idempotencyKey): ChargeResult;

    /**
     * 決済の現在の状態を PSP に問い合わせる。
     *
     * クライアントの申告を信じず、**必ずここで金額と状態を確認する**ために使う。
     *
     * @param  string  $reference  決済参照ID
     *
     * @throws \RuntimeException 参照できない
     */
    public function confirm(string $reference): ChargeResult;
}
