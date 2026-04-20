<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;

class DoctorFactory extends Factory
{
    protected $model = Doctor::class;

    public function definition(): array
    {
        return [
            'facility_id'    => Facility::factory(),
            'external_key'   => $this->faker->unique()->numerify('DR-####'),
            'first_name'     => $this->faker->firstName(),
            'last_name'      => $this->faker->lastName(),
            'specialty'      => $this->faker->randomElement(['Surgery', 'Internal Medicine', 'Emergency', 'Dermatology']),
            'license_number' => 'LIC-' . $this->faker->unique()->numerify('#######'),
            'phone_encrypted' => encrypt('(555) ' . $this->faker->numerify('###-####')),
            'email'          => $this->faker->unique()->email(),
            'active'         => true,
        ];
    }
}
