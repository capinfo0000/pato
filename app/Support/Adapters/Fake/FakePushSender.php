<?php

declare(strict_types=1);

namespace App\Support\Adapters\Fake;

use App\Support\Contracts\PushSender;

/**
 * テスト用プッシュ Fake。送信内容を記録して検証できる。
 */
final class FakePushSender implements PushSender
{
    /** @var list<array{userId:int,title:string,body:string,data:array<string,scalar>}> */
    public array $sent = [];

    public function send(int $userId, string $title, string $body, array $data = []): void
    {
        $this->sent[] = ['userId' => $userId, 'title' => $title, 'body' => $body, 'data' => $data];
    }

    public function countFor(int $userId): int
    {
        return count(array_filter($this->sent, static fn ($m) => $m['userId'] === $userId));
    }
}
