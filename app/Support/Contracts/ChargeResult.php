<?php

declare(strict_types=1);

namespace App\Support\Contracts;

/**
 * 課金の結果。
 *
 * 3Dセキュア（本人認証）が要求されると、その場では決済が完了せず
 * 「ブラウザで認証してから確定する」という2段階になる。日本のカードでは
 * 3Dセキュアが実質必須になりつつあるため、`requires_action` を失敗扱いにしない。
 *
 * `amountYen` / `currency` は、確定時に**サーバ側で金額を検証する**ために持つ。
 * クライアントから渡された決済IDをそのまま信用してポイントを付与すると、
 * 「1円だけ払って10万P受け取る」ができてしまう。
 */
final class ChargeResult
{
    public const SUCCEEDED = 'succeeded';

    public const REQUIRES_ACTION = 'requires_action';

    public function __construct(
        public readonly string $status,
        public readonly string $reference,
        public readonly int $amountYen,
        public readonly string $currency = 'jpy',
        /** 3Dセキュアの認証をブラウザで走らせるために必要。秘密鍵ではない */
        public readonly ?string $clientSecret = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    public function requiresAction(): bool
    {
        return $this->status === self::REQUIRES_ACTION;
    }
}
