<?php

declare(strict_types=1);

namespace App\Support\Adapters\Ekyc;

use App\Support\Contracts\EkycProvider;
use App\Support\DTO\EkycResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * eKYC ベンダの確認結果を HTTP で取得する汎用アダプタ。
 *
 * PII の扱い（docs/04 §5）:
 * - 本人確認書類・顔画像はベンダ側で処理し、当アプリには持ち帰らない
 * - 受け取るのは「確認できたか」「18歳以上か」「ベンダ側の参照ID」だけ
 * - 生年月日はベンダから返っても保持せず、年齢判定の結果のみ使う
 * - ログに氏名・住所・生年月日を出さない
 *
 * ベンダごとにレスポンス形状が違うため、キーのマッピングは設定で差し替えられるようにしてある。
 */
final class HttpEkycProvider implements EkycProvider
{
    /**
     * @param  array{status:string, verified_value:string, birthdate:string}  $map
     *                                                                              ベンダのレスポンスキー対応
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly array $map = [
            'status' => 'status',
            'verified_value' => 'completed',
            'birthdate' => 'date_of_birth',
        ],
        private readonly int $minAge = 18,
    ) {}

    public function verify(string $sessionRef): EkycResult
    {
        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(15)
            ->get(rtrim($this->baseUrl, '/')."/verifications/{$sessionRef}");

        if ($response->failed()) {
            Log::warning('ekyc.fetch_failed', [
                'status' => $response->status(),
                'session_ref' => $sessionRef, // 参照IDのみ。PIIは出さない
            ]);

            return new EkycResult(verified: false, isAdult: false, providerRef: $sessionRef);
        }

        $verified = (string) $response->json($this->map['status']) === $this->map['verified_value'];
        $birthdate = $response->json($this->map['birthdate']);

        return new EkycResult(
            verified: $verified,
            isAdult: $verified && $this->isAdult($birthdate),
            providerRef: $sessionRef,
        );
    }

    /** 生年月日は保持せず、この場で年齢要件の充足だけを判定する。 */
    private function isAdult(mixed $birthdate): bool
    {
        if (! is_string($birthdate) || $birthdate === '') {
            return false;
        }

        try {
            $dob = new \DateTimeImmutable($birthdate);
        } catch (\Exception) {
            return false;
        }

        return $dob->diff(new \DateTimeImmutable('today'))->y >= $this->minAge;
    }
}
