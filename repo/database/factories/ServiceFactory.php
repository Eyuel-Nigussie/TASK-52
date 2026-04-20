<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'external_key'    => $this->faker->unique()->bothify('SVC-####'),
            'name'            => $this->faker->randomElement(['Consultation', 'Vaccination', 'Dental Cleaning', 'Surgery', 'Boarding']),
            'category'        => $this->faker->randomElement(['clinical', 'preventative', 'surgical', 'boarding']),
            'code'            => strtoupper($this->faker->lexify('?????')),
            'description'     => $this->faker->optional()->sentence(),
            'duration_minutes' => $this->faker->randomElement([15, 30, 45, 60, 90]),
            'active'          => true,
        ];
    }
}
