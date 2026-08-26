<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Trust\AccessGate;
use PHPUnit\Framework\TestCase;

final class AccessGateTest extends TestCase
{
    public function test_verified_adult_in_area_can_create_call(): void
    {
        $gate = new AccessGate(identityVerified: true, isAdult: true, areaServiceable: true);

        $this->assertTrue($gate->canCreateCall());
        $this->assertSame([], $gate->reasons());
    }

    public function test_minor_cannot_create_or_participate(): void
    {
        $gate = new AccessGate(identityVerified: true, isAdult: false, areaServiceable: true);

        $this->assertFalse($gate->canCreateCall());
        $this->assertFalse($gate->canParticipate());
        $this->assertContains('under_age', $gate->reasons());
    }

    public function test_unverified_cannot_create_call(): void
    {
        $gate = new AccessGate(identityVerified: false, isAdult: true, areaServiceable: true);

        $this->assertFalse($gate->canCreateCall());
        $this->assertContains('identity_unverified', $gate->reasons());
    }

    public function test_out_of_area_cannot_create_call_but_may_participate(): void
    {
        $gate = new AccessGate(identityVerified: true, isAdult: true, areaServiceable: false);

        $this->assertFalse($gate->canCreateCall());
        $this->assertTrue($gate->canParticipate());
        $this->assertContains('area_not_serviceable', $gate->reasons());
    }
}
