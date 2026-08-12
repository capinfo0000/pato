<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

/**
 * メッセージ/プロフィールの禁止内容を検知する純粋ロジック。
 *
 * 検知対象（要件 FR-M02 / FR-T07 / 法務 04）:
 * - 連絡先の直接交換（電話番号・LINE ID 等）
 * - 外部アプリ/サービスへの誘導
 * - 現金手渡し・直接取引の示唆
 * - 密室（ホテル/自宅）への誘導
 * - 性的サービスの示唆
 *
 * 実際の判定は AI/ルールの併用を想定。ここではルールベースの土台を提供する。
 * 判定はブロックせず「フラグ＋理由」を返し、運用（保留/通報/自動制裁）に委ねる。
 */
final class ContentFilter
{
    /** @var array<string, list<string>> reason => keywords/patterns */
    private const RULES = [
        'contact_exchange' => ['line id', 'line交換', 'ライン交換', 'id交換', 'lineやろ', '電話番号', '090', '080', '070', '@gmail', 'カカオ'],
        'external_solicit' => ['他アプリ', '直接会おう', '公式外', '個人的に', 'こっそり'],
        'cash_direct' => ['現金手渡し', '直接取引', '手渡しで', '現金で直接'],
        'private_room' => ['ホテル', '自宅', '家に来', '部屋で二人', '個室で二人きり'],
        'sexual' => ['性的', 'エッチ', '下ネタ強要'],
    ];

    /**
     * 電話番号らしき数字列（ハイフン許容, 10〜11桁）。
     */
    private const PHONE_REGEX = '/\b0\d{1,3}[-\s]?\d{2,4}[-\s]?\d{3,4}\b/u';

    /**
     * @return list<string> 検知した理由コード（空なら問題なし）
     */
    public function scan(string $text): array
    {
        $normalized = mb_strtolower($text);
        $reasons = [];

        foreach (self::RULES as $reason => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($normalized, mb_strtolower($kw)) !== false) {
                    $reasons[] = $reason;
                    break;
                }
            }
        }

        if (! in_array('contact_exchange', $reasons, true) && preg_match(self::PHONE_REGEX, $text) === 1) {
            $reasons[] = 'contact_exchange';
        }

        return array_values(array_unique($reasons));
    }

    public function isClean(string $text): bool
    {
        return $this->scan($text) === [];
    }
}
