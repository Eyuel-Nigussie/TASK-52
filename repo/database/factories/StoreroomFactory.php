<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Storeroom;
use Illuminate\Database\Eloquent\Factories\Factory;

class StoreroomFactory extends Factory
{
    protected $model = Storeroom::class;

    public function definition(): array
    {
        return [
            'facility_id' => Facility::factory(),
            'name'        => $this->faker->randomElement(['Main Storage', 'Surgery Supply', 'Pharmacy', 'Emergency Stock']),
            'code'        => strtoupper($this->faker->unique()->bothify('SR-###')),
            'active'      => true,
        ];
    }
}
