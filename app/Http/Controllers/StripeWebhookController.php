<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Point\RefundPointService;
use App\Models\PointPurchase;
use App\Models\WebhookEvent;
use App\Support\Adapters\Stripe\StripeSignatureVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stripe からの非同期通知の受け口。
 *
 * 認証なしで外部から叩ける唯一のエンドポイントなので、扱いは特に慎重に:
 * - 署名検証に通らないものは即 400。ボディは一切パースしない
 * - webhook secret 未設定なら常に拒否する（「設定漏れ＝素通し」を作らない）
 * - 同じイベントが何度届いても結果が変わらない（webhook_events の一意制約 + 各サービス側の冪等性）
 * - 処理中に例外が出たらイベント記録ごとロールバックし、5xx を返して Stripe に再送させる
 *   （200 を返してしまうと再送が止まり、返金の取りこぼし＝そのまま損失になる）
 *
 * 返すステータスの意味:
 *   200 受理した（再送不要）/ 400 署名不正 / 5xx 処理失敗（再送してほしい）
 */
final class StripeWebhookController extends Controller
{
    public function __construct(private readonly RefundPointService $refunds) {}

    public function __invoke(Request $request): Response
    {
        $secret = (string) config('services.stripe.webhook_secret', '');
        $payload = $request->getContent();

        $verifier = new StripeSignatureVerifier($secret);
        if (! $verifier->verify($payload, (string) $request->header('Stripe-Signature', ''), time())) {
            // 誰が叩いたかだけ残す。ボディは信用できないのでログにも出さない
            Log::warning('stripe.webhook.invalid_signature', ['ip' => $request->ip()]);

            return response('invalid signature', 400);
        }

        /** @var array<string, mixed> $event */
        $event = json_decode($payload, true) ?: [];
        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');

        if ($eventId === '' || $type === '') {
            return response('malformed', 400);
        }

        try {
            DB::transaction(function () use ($eventId, $type, $event) {
                // 先に記録する。処理が落ちたらこの行ごと消えるので、再送で必ずやり直せる
                WebhookEvent::create(['provider' => 'stripe', 'event_id' => $eventId, 'type' => $type]);

                $this->handle($type, (array) ($event['data']['object'] ?? []));
            });
        } catch (UniqueConstraintViolationException) {
            // 再送。すでに処理済みなので何もしない
            return response('duplicate', 200);
        }

        return response('ok', 200);
    }

    /** @param array<string, mixed> $object */
    private function handle(string $type, array $object): void
    {
        match ($type) {
            'payment_intent.succeeded' => $this->reconcilePayment($object),
            'charge.refunded' => $this->refund($object),
            'charge.dispute.created' => $this->dispute($object),
            default => Log::info('stripe.webhook.ignored', ['type' => $type]),
        };
    }

    /**
     * 入金が確定した通知。ポイント付与は購入処理側で同期的に済んでいるので通常は何もしない。
     * ただし対応する購入記録が無い場合は「入金したのにポイントが無い」状態なので必ず気づけるようにする。
     *
     * @param  array<string, mixed>  $intent
     */
    private function reconcilePayment(array $intent): void
    {
        $ref = (string) ($intent['id'] ?? '');
        if ($ref === '') {
            return;
        }

        if (! PointPurchase::where('charge_ref', $ref)->exists()) {
            Log::error('stripe.webhook.orphan_payment', [
                'charge_ref' => $ref,
                'amount' => $intent['amount_received'] ?? $intent['amount'] ?? null,
            ]);
        }
    }

    /**
     * 返金。amount_refunded は「これまでの累計」なので、そのまま按分に使える。
     *
     * @param  array<string, mixed>  $charge
     */
    private function refund(array $charge): void
    {
        // PaymentIntent 経由でない古い Charge のために charge id もフォールバックで見る
        $ref = $this->firstString($charge, ['payment_intent', 'id']);
        if ($ref === null) {
            Log::error('stripe.webhook.refund_without_ref', ['charge' => $charge['id'] ?? null]);

            return;
        }

        $refundedYen = isset($charge['amount_refunded']) ? (int) $charge['amount_refunded'] : null;

        $this->refunds->refundByChargeRef($ref, $refundedYen);
    }

    /**
     * チャージバック（不正利用申立て）。この時点で入金は引き上げられているため、
     * 争うかどうかに関わらずポイントは全額回収する。
     *
     * @param  array<string, mixed>  $dispute
     */
    private function dispute(array $dispute): void
    {
        // dispute の id（dp_...）は我々の決済参照IDではないので、フォールバックに含めない
        $ref = $this->firstString($dispute, ['payment_intent', 'charge']);
        if ($ref === null) {
            Log::error('stripe.webhook.dispute_without_ref', ['dispute' => $dispute['id'] ?? null]);

            return;
        }

        Log::warning('stripe.webhook.dispute_created', [
            'charge_ref' => $ref,
            'reason' => $dispute['reason'] ?? null,
        ]);

        // 部分金額を渡さない＝全額回収
        $this->refunds->refundByChargeRef($ref);
    }

    /**
     * 我々が保存している決済参照ID（原則 PaymentIntent の ID）を、優先順に探して取り出す。
     *
     * @param  array<string, mixed>  $object
     * @param  list<string>  $keys
     */
    private function firstString(array $object, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $object[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
