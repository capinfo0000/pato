<?php

declare(strict_types=1);

namespace App\Support\Adapters\Fake;

use App\Support\Contracts\EkycProvider;
use App\Support\DTO\EkycResult;

/**
 * テスト用 eKYC Fake。セッション参照ごとに返す結果を仕込める。
 */
final class FakeEkycProvider implements EkycProvider
{
    /** @var array<string, EkycResult> */
    private array $results = [];

    public function stub(string $sessionRef, EkycResult $result): void
    {
        $this->results[$sessionRef] = $result;
    }

    public function verify(string $sessionRef): EkycResult
    {
        return $this->results[$sessionRef]
            ?? new EkycResult(verified: false, isAdult: false, providerRef: $sessionRef);
    }
}
