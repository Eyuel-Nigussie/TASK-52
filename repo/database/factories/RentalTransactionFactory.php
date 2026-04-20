<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Facility;
use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class RentalTransactionFactory extends Factory
{
    protected $model = RentalTransaction::class;

    public function definition(): array
    {
        return [
            'asset_id'           => RentalAsset::factory(),
            'renter_type'        => 'department',
            'renter_id'          => 1,
            'facility_id'        => Facility::factory(),
            'checked_out_at'     => now()->subDays(1),
            'expected_return_at' => now()->addDays(2),
            'status'             => 'active',
            'deposit_collected'  => 50.00,
            'fee_amount'         => 0,
        ];
    }

    public function overdue(): static
    {
        return $this->state([
            'status'             => 'overdue',
            'expected_return_at' => now()->subDays(3),
        ]);
    }

    public function returned(): static
    {
        return $this->state([
            'status'           => 'returned',
            'actual_return_at' => now(),
        ]);
    }
}
