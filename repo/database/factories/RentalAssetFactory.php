<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Facility;
use App\Models\RentalAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

class RentalAssetFactory extends Factory
{
    protected $model = RentalAsset::class;

    public function definition(): array
    {
        $categories = ['infusion_pump', 'ultrasound', 'oxygen_cage', 'x_ray', 'anesthesia_machine'];
        $replacementCost = $this->faker->numberBetween(1000, 50000);
        $depositRate = (float) config('vetops.deposit_rate', 0.20);
        $depositMin = (float) config('vetops.deposit_min', 50.00);

        return [
            'facility_id'      => Facility::factory(),
            'external_key'     => $this->faker->unique()->numerify('ASSET-####'),
            'name'             => $this->faker->words(3, true),
            'category'         => $this->faker->randomElement($categories),
            'manufacturer'     => $this->faker->company(),
            'model_number'     => strtoupper($this->faker->bothify('??###')),
            'serial_number'    => $this->faker->unique()->bothify('SN-########'),
            'barcode'          => $this->faker->unique()->ean13(),
            'qr_code'          => 'QR-' . $this->faker->unique()->uuid(),
            'status'           => 'available',
            'replacement_cost' => $replacementCost,
            'daily_rate'       => round($replacementCost * 0.02, 2),
            'weekly_rate'      => round($replacementCost * 0.10, 2),
            'deposit_amount'   => max($replacementCost * $depositRate, $depositMin),
            'specs'            => ['weight' => '10kg', 'power' => '220V'],
        ];
    }

    public function available(): static
    {
        return $this->state(['status' => 'available']);
    }

    public function rented(): static
    {
        return $this->state(['status' => 'rented']);
    }

    public function inMaintenance(): static
    {
        return $this->state(['status' => 'in_maintenance']);
    }
}
