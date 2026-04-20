<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Department;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;

class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'facility_id'  => Facility::factory(),
            'external_key' => $this->faker->unique()->numerify('DEPT-###'),
            'name'         => $this->faker->randomElement(['Surgery', 'Emergency', 'Oncology', 'Radiology', 'ICU']),
            'code'         => strtoupper($this->faker->bothify('??##')),
            'active'       => true,
        ];
    }
}
