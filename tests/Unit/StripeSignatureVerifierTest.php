<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Adapters\Stripe\StripeSignatureVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Webhook の門番。ここが緩いと架空の返金を注入できるので、
 * 「通るべきものだけが通る」を細かく固定する。
 */
final class StripeSignatureVerifierTest extends TestCase
{
    private const SECRET = 'whsec_abc';

    public function test_accepts_a_valid_signature(): void
    {
        $verifier = new StripeSignatureVerifier(self::SECRET);

        $this->assertTrue($verifier->verify('{"a":1}', $this->header('{"a":1}', 1_700_000_000), 1_700_000_000));
    }

    public function test_accepts_when_one_of_several_signatures_matches(): void
    {
        // シークレットのローテーション中は v1 が複数付く
        $verifier = new StripeSignatureVerifier(self::SECRET);
        $t = 1_700_000_000;
        $header = 't='.$t.',v1='.str_repeat('0', 64).','.'v1='.hash_hmac('sha256', $t.'.{"a":1}', self::SECRET);

        $this->assertTrue($verifier->verify('{"a":1}', $header, $t));
    }

    public function test_rejects_when_the_body_differs_by_one_byte(): void
    {
        $verifier = new StripeSignatureVerifier(self::SECRET);
        $header = $this->header('{"a":1}', 1_700_000_000);

        $this->assertFalse($verifier->verify('{"a":2}', $header, 1_700_000_000));
    }

    public function test_rejects_a_signature_made_with_another_secret(): void
    {
        $t = 1_700_000_000;
        $header = 't='.$t.',v1='.hash_hmac('sha256', $t.'.{"a":1}', 'whsec_other');

        $this->assertFalse((new StripeSignatureVerifier(self::SECRET))->verify('{"a":1}', $header, $t));
    }

    public function test_rejects_signatures_outside_the_tolerance_window(): void
    {
        $verifier = new StripeSignatureVerifier(self::SECRET);
        $t = 1_700_000_000;
        $header = $this->header('{"a":1}', $t);
        $tolerance = StripeSignatureVerifier::TOLERANCE_SECONDS;

        // 境界内は通る
        $this->assertTrue($verifier->verify('{"a":1}', $header, $t + $tolerance));
        // 古すぎる（リプレイ）
        $this->assertFalse($verifier->verify('{"a":1}', $header, $t + $tolerance + 1));
        // 未来すぎる（時刻を偽装したもの）
        $this->assertFalse($verifier->verify('{"a":1}', $header, $t - $tolerance - 1));
    }

    public function test_rejects_when_no_secret_is_configured(): void
    {
        // 設定漏れを「検証成功」にしない
        $t = 1_700_000_000;

        $this->assertFalse((new StripeSignatureVerifier(''))->verify('{"a":1}', 't='.$t.',v1=x', $t));
    }

    /** @return list<array{0: string}> */
    public static function malformedHeaders(): array
    {
        return [
            'empty' => [''],
            'no timestamp' => ['v1='.str_repeat('0', 64)],
            'no signature' => ['t=1700000000'],
            'non-numeric timestamp' => ['t=abc,v1='.str_repeat('0', 64)],
            'v0 only (未対応スキーム)' => ['t=1700000000,v0='.str_repeat('0', 64)],
            'garbage' => ['not-a-signature'],
        ];
    }

    #[DataProvider('malformedHeaders')]
    public function test_rejects_malformed_headers(string $header): void
    {
        $this->assertFalse((new StripeSignatureVerifier(self::SECRET))->verify('{"a":1}', $header, 1_700_000_000));
    }

    private function header(string $payload, int $t): string
    {
        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, self::SECRET);
    }
}
