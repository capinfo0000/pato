<?php

declare(strict_types=1);

namespace App\Domain\Call;

use App\Domain\Call\Enums\CallStatus;

/**
 * patoコールのエンティティ（インメモリ表現）。
 * 状態遷移は必ず CallStateMachine を通す。
 */
final class Call
{
    private CallStatus $status;

    /**
     * @param  list<int>  $participantCastIds  成立時に確定する参加キャスト
     */
    public function __construct(
        public readonly int $id,
        public readonly int $guestUserId,
        public readonly int $holdPoints,
        private array $participantCastIds = [],
        CallStatus $status = CallStatus::Draft,
        private readonly CallStateMachine $sm = new CallStateMachine,
    ) {
        $this->status = $status;
    }

    public function status(): CallStatus
    {
        return $this->status;
    }

    /** @return list<int> */
    public function participantCastIds(): array
    {
        return $this->participantCastIds;
    }

    private function transitionTo(CallStatus $to): void
    {
        $this->sm->assertCanTransition($this->status, $to);
        $this->status = $to;
    }

    public function open(): void
    {
        $this->transitionTo(CallStatus::Open);
    }

    /** @param list<int> $castIds */
    public function match(array $castIds): void
    {
        if ($castIds === []) {
            throw new \DomainException('成立には1名以上の参加が必要');
        }
        $this->transitionTo(CallStatus::Matched);
        $this->participantCastIds = array_values($castIds);
    }

    public function start(): void
    {
        $this->transitionTo(CallStatus::InProgress);
    }

    public function complete(): void
    {
        $this->transitionTo(CallStatus::Completed);
    }

    public function cancel(): void
    {
        $this->transitionTo(CallStatus::Canceled);
    }

    public function expire(): void
    {
        $this->transitionTo(CallStatus::Expired);
    }
}
