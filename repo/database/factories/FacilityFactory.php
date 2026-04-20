<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;

class FacilityFactory extends Factory
{
    protected $model = Facility::class;

    public function definition(): array
    {
        return [
            'external_key'    => $this->faker->unique()->numerify('FAC-####'),
            'name'            => $this->faker->company() . ' Veterinary Hospital',
            'address'         => $this->faker->streetAddress(),
            'city'            => $this->faker->city(),
            'state'           => $this->faker->stateAbbr(),
            'zip'             => $this->faker->postcode(),
            'phone_encrypted' => encrypt('(555) ' . $this->faker->numerify('###-####')),
            'email'           => $this->faker->companyEmail(),
            'business_hours'  => ['mon-fri' => '8am-6pm', 'sat' => '9am-2pm'],
            'active'          => true,
        ];
    }
}
