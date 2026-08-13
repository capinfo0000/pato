<?php

declare(strict_types=1);

namespace App\Support\Adapters\Push;

use App\Jobs\SendPushJob;
use App\Models\PushSubscription;
use App\Support\Contracts\PushSender;

/**
 * Web Push の実アダプタ。
 *
 * 送信はネットワーク I/O なので、リクエスト内では投げっぱなしにせず Queue に逃がす。
 * （成立通知や SOS のたびに外部接続でレスポンスが遅くなるのを避ける）
 *
 * 実際の暗号化送信（VAPID / aes128gcm）は web-push ライブラリに委ねる想定で、
 * ここでは購読の解決とジョブ投入までを担う。
 */
final class WebPushSender implements PushSender
{
    public function send(int $userId, string $title, string $body, array $data = []): void
    {
        $subscriptionIds = PushSubscription::where('user_id', $userId)->pluck('id');

        foreach ($subscriptionIds as $subscriptionId) {
            SendPushJob::dispatch($subscriptionId, $title, $body, $data);
        }
    }
}
