<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Visit;
use App\Models\VisitReview;
use Illuminate\Database\Eloquent\Factories\Factory;

class VisitReviewFactory extends Factory
{
    protected $model = VisitReview::class;

    public function definition(): array
    {
        $visit = Visit::factory()->completed()->create();
        return [
            'visit_id'          => $visit->id,
            'facility_id'       => $visit->facility_id,
            'doctor_id'         => $visit->doctor_id,
            'rating'            => $this->faker->numberBetween(1, 5),
            'tags'              => ['friendly', 'professional'],
            'body'              => $this->faker->paragraph(),
            'status'            => 'published',
            'submitted_at'      => now(),
            'submitted_by_name' => $this->faker->name(),
        ];
    }
}
