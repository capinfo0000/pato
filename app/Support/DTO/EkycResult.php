<?php

declare(strict_types=1);

namespace App\Support\DTO;

/**
 * eKYC の結果。生年月日は PII なのでログに出さない（マスキング前提）。
 */
final readonly class EkycResult
{
    public function __construct(
        public bool $verified,
        public bool $isAdult,
        public string $providerRef,
    ) {
    }
}
