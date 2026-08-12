<?php

declare(strict_types=1);

namespace App\Domain\Call;

use App\Domain\Call\Enums\CallStatus;

/**
 * patoコールの状態遷移を一元管理する。
 *
 * 状態はここでしか進めない（モデルを直接 save して状態を飛ばさない）。
 * 各遷移は与信・通知・精算の副作用と対応するため、許可された遷移のみ通す。
 */
final class CallStateMachine
{
    /** @var array<string, list<CallStatus>> */
    private const TRANSITIONS = [
        'draft' => [CallStatus::Open],
        'open' => [CallStatus::Matched, CallStatus::Canceled, CallStatus::Expired],
        'matched' => [CallStatus::InProgress, CallStatus::Canceled],
        'in_progress' => [CallStatus::Completed],
        'completed' => [],
        'canceled' => [],
        'expired' => [],
    ];

    public function canTransition(CallStatus $from, CallStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * @throws \DomainException 許可されない遷移のとき
     */
    public function assertCanTransition(CallStatus $from, CallStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new \DomainException("不正な状態遷移: {$from->value} → {$to->value}");
        }
    }

    public function isTerminal(CallStatus $status): bool
    {
        return self::TRANSITIONS[$status->value] === [];
    }
}
