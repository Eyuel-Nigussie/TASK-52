<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Facility;
use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use App\Models\User;
use App\Services\RentalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unit-level coverage for RentalService: checkout concurrency guard, return
 * state machine, fee calculation branches (daily vs weekly+remaining),
 * and the overdue marker.
 */
class RentalServiceTest extends TestCase
{
    use RefreshDatabase;

    private RentalService $rental;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rental = app(RentalService::class);
    }

    public function test_checkout_creates_active_transaction_and_locks_asset(): void
    {
        $asset = RentalAsset::factory()->create(['status' => 'available']);
        $user  = User::factory()->create();

        $txn = $this->rental->checkout(
            $asset,
            'department',
            1,
            $asset->facility_id,
            Carbon::now()->addDays(3),
            $user->id,
        );

        $this->assertEquals('active', $txn->status);
        $this->assertEquals('rented', $asset->fresh()->status);
    }

    public function test_checkout_blocks_already_rented_asset(): void
    {
        $this->expectException(ValidationException::class);

        $asset = RentalAsset::factory()->create(['status' => 'rented']);
        RentalTransaction::factory()->create([
            'asset_id' => $asset->id,
            'status'   => 'active',
        ]);

        $this->rental->checkout(
            $asset,
            'department',
            1,
            $asset->facility_id,
            Carbon::now()->addDays(1),
            1,
        );
    }

    public function test_return_moves_txn_to_returned_and_asset_to_available(): void
    {
        $asset = RentalAsset::factory()->create(['status' => 'rented', 'daily_rate' => 10]);
        $txn   = RentalTransaction::factory()->create([
            'asset_id'       => $asset->id,
            'checked_out_at' => now()->subDays(2),
            'status'         => 'active',
        ]);

        $returned = $this->rental->return($txn, 1);

        $this->assertEquals('returned', $returned->status);
        $this->assertEquals('available', $asset->fresh()->status);
        $this->assertGreaterThan(0, (float) $returned->fee_amount);
    }

    public function test_return_rejects_second_return(): void
    {
        $this->expectException(ValidationException::class);

        $asset = RentalAsset::factory()->create(['status' => 'available']);
        $txn   = RentalTransaction::factory()->create([
            'asset_id' => $asset->id,
            'status'   => 'returned',
        ]);

        $this->rental->return($txn, 1);
    }

    public function test_fee_uses_weekly_rate_when_days_exceed_seven(): void
    {
        $asset = RentalAsset::factory()->create([
            'daily_rate'  => 10,
            'weekly_rate' => 50,
        ]);
        $txn = RentalTransaction::factory()->create([
            'asset_id'        => $asset->id,
            'checked_out_at'  => now()->subDays(10), // 10 days => 1 week + 3 days
            'actual_return_at' => now(),
            'status'           => 'returned',
        ]);

        $fee = $this->rental->calculateFee($txn);

        // Expected: 1 week ($50) + 3 days ($30) = $80
        $this->assertEquals(80.0, $fee);
    }

    public function test_fee_daily_only_when_under_one_week(): void
    {
        $asset = RentalAsset::factory()->create(['daily_rate' => 15, 'weekly_rate' => 0]);
        $txn = RentalTransaction::factory()->create([
            'asset_id'         => $asset->id,
            'checked_out_at'   => now()->subDays(3),
            'actual_return_at' => now(),
        ]);

        $this->assertEquals(45.0, $this->rental->calculateFee($txn));
    }

    public function test_mark_overdue_transitions_active_past_due_transactions(): void
    {
        $overdueHours = (int) config('vetops.overdue_hours', 2);

        $asset = RentalAsset::factory()->create(['status' => 'rented']);
        $late  = RentalTransaction::factory()->create([
            'asset_id'           => $asset->id,
            'status'             => 'active',
            'expected_return_at' => now()->subHours($overdueHours + 1),
        ]);
        $onTime = RentalTransaction::factory()->create([
            'asset_id'           => RentalAsset::factory()->create(['status' => 'rented'])->id,
            'status'             => 'active',
            'expected_return_at' => now()->addDays(2),
        ]);

        $updated = $this->rental->markOverdue();

        $this->assertGreaterThanOrEqual(1, $updated);
        $this->assertEquals('overdue', RentalTransaction::find($late->id)->status);
        $this->assertEquals('active', RentalTransaction::find($onTime->id)->status);
    }

    public function test_get_overdue_transactions_filters_to_past_due(): void
    {
        $overdueHours = (int) config('vetops.overdue_hours', 2);
        $asset = RentalAsset::factory()->create(['status' => 'rented']);

        $overdue = RentalTransaction::factory()->create([
            'asset_id'           => $asset->id,
            'status'             => 'active',
            'expected_return_at' => now()->subHours($overdueHours + 5),
        ]);
        RentalTransaction::factory()->create([
            'asset_id'           => RentalAsset::factory()->create(['status' => 'rented'])->id,
            'status'             => 'active',
            'expected_return_at' => now()->addDays(1),
        ]);

        $result = $this->rental->getOverdueTransactions();

        $ids = $result->pluck('id')->all();
        $this->assertContains($overdue->id, $ids);
    }
}
