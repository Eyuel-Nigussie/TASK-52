<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Facility;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceOrderFactory extends Factory
{
    protected $model = ServiceOrder::class;

    public function definition(): array
    {
        return [
            'facility_id'          => Facility::factory(),
            'patient_id'           => null,
            'doctor_id'            => null,
            'status'               => 'open',
            'reservation_strategy' => $this->faker->randomElement(['lock_at_creation', 'deduct_at_close']),
            'created_by'           => User::factory(),
        ];
    }
}
