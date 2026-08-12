<?php

declare(strict_types=1);

namespace App\Domain\Trust;

/**
 * 呼び出し作成・参加の入口ゲート（純粋ロジック）。
 *
 * - 18歳未満は不可（出会い系規制法・大原則）
 * - 本人確認(eKYC)未完了は不可
 * - サービス対象エリア外は不可（岡山限定）
 *
 * ミドルウェアはこの判定を使って入口で弾く。
 */
final class AccessGate
{
    public const MIN_AGE = 18;

    public function __construct(
        private readonly bool $identityVerified,
        private readonly bool $isAdult,
        private readonly bool $areaServiceable,
    ) {}

    public function canCreateCall(): bool
    {
        return $this->identityVerified && $this->isAdult && $this->areaServiceable;
    }

    public function canParticipate(): bool
    {
        // 参加（キャスト側）はエリア判定を呼び出し側に委ねるため、本人確認と年齢のみ。
        return $this->identityVerified && $this->isAdult;
    }

    /**
     * 不可の理由（UI表示・ログ用）。PII は含めない。
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        $reasons = [];
        if (! $this->isAdult) {
            $reasons[] = 'under_age';
        }
        if (! $this->identityVerified) {
            $reasons[] = 'identity_unverified';
        }
        if (! $this->areaServiceable) {
            $reasons[] = 'area_not_serviceable';
        }

        return $reasons;
    }
}
