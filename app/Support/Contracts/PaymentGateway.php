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
     * ポイント購入の課金。成功時に PSP の参照IDを返す。
     *
     * @param  string  $paymentMethodToken  PSP 発行のトークン（カード番号は扱わない）
     * @param  int  $amountYen  請求額（円・整数）
     * @return string 決済参照ID
     *
     * @throws \RuntimeException 決済失敗
     */
    public function charge(string $paymentMethodToken, int $amountYen, string $idempotencyKey): string;
}
