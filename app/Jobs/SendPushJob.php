<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Support\Adapters\Push\WebPushFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;

/**
 * Web Push を1購読へ送る。
 *
 * VAPID 署名（ES256 JWT）とペイロード暗号化（aes128gcm）は minishlink/web-push に委ねる。
 *
 * - 404/410 は購読の失効なので、購読を削除して再送しない
 * - 本文に PII を含めない（通知は「何が起きたか」だけ伝え、詳細はアプリで見せる）
 */
final class SendPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    /** @param array<string, scalar> $data */
    public function __construct(
        private readonly int $subscriptionId,
        private readonly string $title,
        private readonly string $body,
        private readonly array $data = [],
    ) {}

    public function handle(): void
    {
        $subscription = PushSubscription::find($this->subscriptionId);
        if ($subscription === null) {
            return; // 購読が消えている
        }

        $vapid = (array) config('services.webpush');
        if (blank($vapid['public_key'] ?? null) || blank($vapid['private_key'] ?? null)) {
            Log::info('webpush.skipped_no_vapid', ['subscription_id' => $subscription->id]);

            return;
        }

        $webPush = app(WebPushFactory::class)->make();

        $report = $webPush->sendOneNotification(
            Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
            ]),
            $this->payload(),
            ['TTL' => 600],
        );

        if ($report->isSuccess()) {
            return;
        }

        // 失効した購読は掃除する（404 Not Found / 410 Gone）
        if ($report->isSubscriptionExpired()) {
            $subscription->delete();

            return;
        }

        Log::warning('webpush.send_failed', [
            'status' => $report->getResponse()?->getStatusCode(),
            'subscription_id' => $subscription->id,
        ]);

        $this->release($this->backoff);
    }

    private function payload(): string
    {
        return json_encode([
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
