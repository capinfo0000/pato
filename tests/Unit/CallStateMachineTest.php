<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Call\CallStateMachine;
use App\Domain\Call\Enums\CallStatus;
use PHPUnit\Framework\TestCase;

final class CallStateMachineTest extends TestCase
{
    private CallStateMachine $sm;

    protected function setUp(): void
    {
        $this->sm = new CallStateMachine();
    }

    public function test_happy_path_transitions_are_allowed(): void
    {
        $this->assertTrue($this->sm->canTransition(CallStatus::Draft, CallStatus::Open));
        $this->assertTrue($this->sm->canTransition(CallStatus::Open, CallStatus::Matched));
        $this->assertTrue($this->sm->canTransition(CallStatus::Matched, CallStatus::InProgress));
        $this->assertTrue($this->sm->canTransition(CallStatus::InProgress, CallStatus::Completed));
    }

    public function test_cancellation_and_expiry(): void
    {
        $this->assertTrue($this->sm->canTransition(CallStatus::Open, CallStatus::Canceled));
        $this->assertTrue($this->sm->canTransition(CallStatus::Open, CallStatus::Expired));
        $this->assertTrue($this->sm->canTransition(CallStatus::Matched, CallStatus::Canceled));
    }

    public function test_illegal_skips_are_rejected(): void
    {
        $this->assertFalse($this->sm->canTransition(CallStatus::Draft, CallStatus::Completed));
        $this->assertFalse($this->sm->canTransition(CallStatus::Open, CallStatus::InProgress));
        $this->assertFalse($this->sm->canTransition(CallStatus::Completed, CallStatus::Open));
    }

    public function test_assert_throws_on_illegal_transition(): void
    {
        $this->expectException(\DomainException::class);
        $this->sm->assertCanTransition(CallStatus::Completed, CallStatus::Open);
    }

    public function test_terminal_states(): void
    {
        $this->assertTrue($this->sm->isTerminal(CallStatus::Completed));
        $this->assertTrue($this->sm->isTerminal(CallStatus::Canceled));
        $this->assertTrue($this->sm->isTerminal(CallStatus::Expired));
        $this->assertFalse($this->sm->isTerminal(CallStatus::Open));
    }
}
