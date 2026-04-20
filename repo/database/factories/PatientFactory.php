<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        return [
            'facility_id'           => Facility::factory(),
            'external_key'          => $this->faker->unique()->numerify('PAT-####'),
            'name'                  => $this->faker->firstName() . ' ' . $this->faker->randomElement(['Smith', 'Jones', 'Brown']),
            'species'               => $this->faker->randomElement(['canine', 'feline', 'avian']),
            'breed'                 => $this->faker->randomElement(['Labrador', 'Siamese', 'Bulldog']),
            'owner_name'            => $this->faker->name(),
            'owner_phone_encrypted' => encrypt('(555) ' . $this->faker->numerify('###-####')),
            'owner_email'           => $this->faker->email(),
            'active'                => true,
        ];
    }
}
