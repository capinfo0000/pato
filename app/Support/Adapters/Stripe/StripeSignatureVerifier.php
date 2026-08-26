<?php

declare(strict_types=1);

namespace App\Support\Adapters\Stripe;

/**
 * Stripe Webhook の署名検証。
 *
 * Webhook は認証なしで外部から叩ける口なので、ここが唯一の門番になる。
 * 突破されると「返金されていないのに返金扱い」「架空の入金」を作られるため、
 * 検証は必ず生ボディに対して行い、比較は必ず定数時間で行う。
 *
 * ヘッダ形式: `t=1699999999,v1=<hex>,v1=<hex>`
 * 署名対象:   `{t}.{生ボディ}` を webhook secret で HMAC-SHA256（hex）
 *
 * @see https://docs.stripe.com/webhooks/signature
 */
final class StripeSignatureVerifier
{
    /** 再送攻撃(リプレイ)を防ぐための許容時間差。Stripe 推奨値。 */
    public const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly string $secret) {}

    /**
     * @param  string  $payload  生のリクエストボディ（JSON デコード前・整形前）
     * @param  string  $header  Stripe-Signature ヘッダの値
     * @param  int  $now  検証時刻（UNIX 秒）
     */
    public function verify(string $payload, string $header, int $now): bool
    {
        if ($this->secret === '') {
            // シークレット未設定で「検証成功」にしてはいけない
            return false;
        }

        $parsed = $this->parse($header);
        if ($parsed['t'] === null || $parsed['v1'] === []) {
            return false;
        }

        // 古い署名の使い回しを拒否する（未来方向のズレも同様に拒否）
        if (abs($now - $parsed['t']) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $parsed['t'].'.'.$payload, $this->secret);

        foreach ($parsed['v1'] as $candidate) {
            // hash_equals: 先頭一致の長さから秘密を推測されないよう定数時間で比較する
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{t: int|null, v1: list<string>}
     */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        return ['t' => $timestamp, 'v1' => $signatures];
    }
}
