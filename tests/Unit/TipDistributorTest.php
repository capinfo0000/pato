<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Call\Support\TipDistributor;
use PHPUnit\Framework\TestCase;

final class TipDistributorTest extends TestCase
{
    private TipDistributor $d;

    protected function setUp(): void
    {
        $this->d = new TipDistributor();
    }

    public function test_even_split(): void
    {
        $this->assertSame([1 => 2500, 2 => 2500], $this->d->distribute(5000, [1, 2]));
    }

    public function test_remainder_goes_to_leading_casts(): void
    {
        // 10,000 を 3人 → 3334, 3333, 3333
        $this->assertSame([1 => 3334, 2 => 3333, 3 => 3333], $this->d->distribute(10000, [1, 2, 3]));
    }

    public function test_explicit_distribution_is_respected(): void
    {
        $result = $this->d->distribute(5000, [1, 2], [1 => 4000, 2 => 1000]);
        $this->assertSame([1 => 4000, 2 => 1000], $result);
    }

    public function test_explicit_must_sum_to_total(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->d->distribute(5000, [1, 2], [1 => 4000, 2 => 2000]);
    }

    public function test_explicit_must_cover_all_participants(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->d->distribute(5000, [1, 2], [1 => 5000]);
    }
}
