<?php

declare(strict_types=1);

namespace App\Support\Contracts;

/**
 * プッシュ通知（Web Push 等）の抽象。成立通知・コンシェルジュ案内などに使う。
 */
interface PushSender
{
    /**
     * @param array<string, scalar> $data 付随データ（PIIを含めない）
     */
    public function send(int $userId, string $title, string $body, array $data = []): void;
}
