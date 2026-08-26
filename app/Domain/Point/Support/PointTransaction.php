<?php

declare(strict_types=1);

namespace App\Domain\Point\Support;

use App\Domain\Point\Enums\PointKind;
use App\Domain\Point\Enums\TransactionType;

/**
 * 台帳の1行を表す不変の値オブジェクト。
 *
 * points は常に「正の絶対量」で保持し、符号は TransactionType が決める。
 * こうすることで金額の符号ミスを型で防ぎ、監査時の読みやすさも保つ。
 */
final readonly class PointTransaction
{
    public function __construct(
        public TransactionType $type,
        public PointKind $kind,
        public int $points,
        public ?int $callId = null,
        public ?string $expiresOn = null,
    ) {
        if ($points < 0) {
            throw new \InvalidArgumentException('points は正の絶対量で指定する（符号は type が決める）');
        }
    }

    public static function purchase(int $points, ?string $expiresOn = null): self
    {
        return new self(TransactionType::Purchase, PointKind::Paid, $points, null, $expiresOn);
    }

    public static function grant(int $points, ?string $expiresOn = null): self
    {
        return new self(TransactionType::Grant, PointKind::Free, $points, null, $expiresOn);
    }

    public static function hold(int $points, int $callId): self
    {
        return new self(TransactionType::Hold, PointKind::Paid, $points, $callId);
    }

    public static function capture(int $points, int $callId): self
    {
        return new self(TransactionType::Capture, PointKind::Paid, $points, $callId);
    }

    public static function release(int $points, int $callId): self
    {
        return new self(TransactionType::Release, PointKind::Paid, $points, $callId);
    }

    public static function tip(int $points, int $callId): self
    {
        return new self(TransactionType::Tip, PointKind::Paid, $points, $callId);
    }

    public static function expire(int $points, PointKind $kind): self
    {
        return new self(TransactionType::Expire, $kind, $points);
    }
}
