<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\StocktakeEntry;
use Tests\TestCase;

class StocktakeVarianceTest extends TestCase
{
    public function test_variance_calculation_positive_difference(): void
    {
        $variance = StocktakeEntry::calculateVariancePct(100, 110);
        $this->assertEquals(10.0, $variance);
    }

    public function test_variance_calculation_negative_difference(): void
    {
        $variance = StocktakeEntry::calculateVariancePct(100, 90);
        $this->assertEquals(10.0, $variance);
    }

    public function test_variance_zero_when_counts_match(): void
    {
        $variance = StocktakeEntry::calculateVariancePct(100, 100);
        $this->assertEquals(0.0, $variance);
    }

    public function test_variance_100_percent_when_system_is_zero_and_counted_positive(): void
    {
        $variance = StocktakeEntry::calculateVariancePct(0, 10);
        $this->assertEquals(100.0, $variance);
    }

    public function test_variance_zero_when_both_zero(): void
    {
        $variance = StocktakeEntry::calculateVariancePct(0, 0);
        $this->assertEquals(0.0, $variance);
    }

    public function test_5_percent_variance_does_not_require_approval(): void
    {
        $entry = new StocktakeEntry(['variance_pct' => 5.0]);
        $this->assertFalse($entry->requiresManagerApproval());
    }

    public function test_5_1_percent_variance_requires_approval(): void
    {
        $entry = new StocktakeEntry(['variance_pct' => 5.1]);
        $this->assertTrue($entry->requiresManagerApproval());
    }

    public function test_exact_5_percent_at_boundary(): void
    {
        // Exactly 5% should NOT require approval (> 5%, not >= 5%)
        $entry = new StocktakeEntry(['variance_pct' => 5.0]);
        $this->assertFalse($entry->requiresManagerApproval());
    }

    public function test_6_percent_variance_requires_approval(): void
    {
        $entry = new StocktakeEntry(['variance_pct' => 6.0]);
        $this->assertTrue($entry->requiresManagerApproval());
    }

    public function test_small_variance_formula(): void
    {
        $variance = StocktakeEntry::calculateVariancePct(1000, 998);
        $this->assertEquals(0.2, $variance);
    }
}
