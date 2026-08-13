<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PushSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Web Push を1購読へ送る。
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

        $vapid = config('services.webpush');
        if (blank($vapid['public_key'] ?? null) || blank($vapid['private_key'] ?? null)) {
            Log::info('webpush.skipped_no_vapid', ['subscription_id' => $subscription->id]);

            return;
        }

        $response = Http::withHeaders($this->headers($subscription))
            ->withBody($this->payload(), 'application/octet-stream')
            ->post($subscription->endpoint);

        // 失効した購読は掃除する
        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();

            return;
        }

        if ($response->failed()) {
            Log::warning('webpush.send_failed', [
                'status' => $response->status(),
                'subscription_id' => $subscription->id,
            ]);

            $this->release($this->backoff);
        }
    }

    /** @return array<string, string> */
    private function headers(PushSubscription $subscription): array
    {
        return [
            'TTL' => '600',
            'Content-Encoding' => 'aes128gcm',
            // VAPID の署名ヘッダは web-push ライブラリ導入時にここで組み立てる
        ];
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
