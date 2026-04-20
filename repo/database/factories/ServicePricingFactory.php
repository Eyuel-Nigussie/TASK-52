<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Service;
use App\Models\ServicePricing;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServicePricingFactory extends Factory
{
    protected $model = ServicePricing::class;

    public function definition(): array
    {
        return [
            'service_id'    => Service::factory(),
            'facility_id'   => Facility::factory(),
            'base_price'    => $this->faker->randomFloat(2, 25, 500),
            'currency'      => 'USD',
            'effective_from' => now()->subDays(30)->toDateString(),
            'effective_to'  => null,
            'active'        => true,
        ];
    }
}
