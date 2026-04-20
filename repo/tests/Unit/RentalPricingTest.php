<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use App\Services\RentalService;
use Carbon\Carbon;
use Tests\TestCase;

class RentalPricingTest extends TestCase
{
    private RentalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $auditMock = $this->createMock(\App\Services\AuditService::class);
        $this->service = new RentalService($auditMock);
    }

    public function test_daily_rate_calculation(): void
    {
        $asset = new RentalAsset(['daily_rate' => 50.00, 'weekly_rate' => 0.00]);
        $transaction = new RentalTransaction([
            'asset_id'       => 1,
            'checked_out_at' => Carbon::now()->subDays(3),
            'actual_return_at' => Carbon::now(),
        ]);
        $transaction->setRelation('asset', $asset);

        $fee = $this->service->calculateFee($transaction);
        $this->assertEquals(150.00, $fee); // 3 days * $50
    }

    public function test_weekly_rate_applied_for_7_plus_days(): void
    {
        $asset = new RentalAsset(['daily_rate' => 50.00, 'weekly_rate' => 280.00]);
        $transaction = new RentalTransaction([
            'asset_id'        => 1,
            'checked_out_at'  => Carbon::now()->subDays(7),
            'actual_return_at' => Carbon::now(),
        ]);
        $transaction->setRelation('asset', $asset);

        $fee = $this->service->calculateFee($transaction);
        $this->assertEquals(280.00, $fee); // 1 week at weekly rate
    }

    public function test_deposit_default_20_percent(): void
    {
        $asset = new RentalAsset([
            'replacement_cost' => 1000.00,
        ]);

        $deposit = $asset->calculateDeposit();
        $this->assertEquals(200.00, $deposit);
    }

    public function test_deposit_minimum_50_enforced(): void
    {
        $asset = new RentalAsset([
            'replacement_cost' => 100.00,  // 20% = 20, but min = 50
        ]);

        $deposit = $asset->calculateDeposit();
        $this->assertEquals(50.00, $deposit);
    }

    public function test_deposit_minimum_not_applied_when_not_needed(): void
    {
        $asset = new RentalAsset([
            'replacement_cost' => 5000.00,  // 20% = 1000
        ]);

        $deposit = $asset->calculateDeposit();
        $this->assertEquals(1000.00, $deposit);
    }

    public function test_overdue_detection_2_hours_threshold(): void
    {
        $transaction = new RentalTransaction([
            'status'             => 'active',
            'expected_return_at' => Carbon::now()->subHours(3), // 3 hours overdue
        ]);

        $this->assertTrue($transaction->isOverdue());
    }

    public function test_not_overdue_within_threshold(): void
    {
        $transaction = new RentalTransaction([
            'status'             => 'active',
            'expected_return_at' => Carbon::now()->addHours(1), // future return
        ]);

        $this->assertFalse($transaction->isOverdue());
    }

    public function test_minimum_1_day_fee_for_under_24_hours(): void
    {
        $asset = new RentalAsset(['daily_rate' => 50.00, 'weekly_rate' => 0.00]);
        $transaction = new RentalTransaction([
            'asset_id'        => 1,
            'checked_out_at'  => Carbon::now()->subHours(2),
            'actual_return_at' => Carbon::now(),
        ]);
        $transaction->setRelation('asset', $asset);

        $fee = $this->service->calculateFee($transaction);
        $this->assertEquals(50.00, $fee); // minimum 1 day
    }
}
