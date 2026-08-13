<?php

declare(strict_types=1);

namespace App\Support\Adapters\Push;

use Minishlink\WebPush\WebPush;

/**
 * WebPush クライアントの生成をひとつにまとめる。
 *
 * web-push は Laravel の HTTP クライアントではなく Guzzle を直接使うため、
 * テストからは このファクトリを差し替えて（モックハンドラを注入して）検証する。
 */
class WebPushFactory
{
    /** @param array<string, mixed> $clientOptions Guzzle のオプション（テストで handler を差す） */
    public function make(array $clientOptions = []): WebPush
    {
        $vapid = (array) config('services.webpush');

        return new WebPush(
            [
                'VAPID' => [
                    'subject' => $vapid['subject'] ?: config('app.url'),
                    'publicKey' => $vapid['public_key'],
                    'privateKey' => $vapid['private_key'],
                ],
            ],
            defaultOptions: [],
            timeout: 10,
            clientOptions: $clientOptions,
        );
    }
}
