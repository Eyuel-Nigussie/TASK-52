<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VisitFactory extends Factory
{
    protected $model = Visit::class;

    public function definition(): array
    {
        $facility = Facility::factory()->create();
        return [
            'facility_id'  => $facility->id,
            'patient_id'   => Patient::factory()->create(['facility_id' => $facility->id])->id,
            'doctor_id'    => Doctor::factory()->create(['facility_id' => $facility->id])->id,
            'visit_date'   => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'status'       => 'completed',
            'review_token' => (string) Str::uuid(),
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => 'completed', 'review_token' => (string) Str::uuid()]);
    }
}
