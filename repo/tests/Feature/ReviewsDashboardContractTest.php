<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\Visit;
use App\Models\VisitReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract test between the backend ReviewService dashboard payload and
 * the frontend ReviewsView. The three keys below are the ones rendered in
 * the three dashboard cards; renaming any of them without updating the
 * view would silently break the UI.
 */
class ReviewsDashboardContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_expected_contract_keys(): void
    {
        $facility = Facility::factory()->create();
        $doctor = Doctor::factory()->create(['facility_id' => $facility->id]);
        $patient = Patient::factory()->create(['facility_id' => $facility->id]);
        $visit = Visit::factory()->create([
            'facility_id' => $facility->id,
            'doctor_id'   => $doctor->id,
            'patient_id'  => $patient->id,
            'status'      => 'completed',
        ]);
        VisitReview::factory()->create([
            'visit_id'     => $visit->id,
            'facility_id'  => $facility->id,
            'doctor_id'   => $doctor->id,
            'rating'       => 5,
            'status'       => 'published',
            'submitted_at' => now()->subHour(),
        ]);

        $this->actingAsAdmin();
        $response = $this->getJson('/api/reviews/dashboard?facility_id=' . $facility->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total',
                'average_rating',
                'negative_review_rate',
                'median_response_time_hours',
            ]);
    }

    public function test_dashboard_requires_facility_for_admin(): void
    {
        $this->actingAsAdmin();
        $this->getJson('/api/reviews/dashboard')->assertStatus(422);
    }

    public function test_dashboard_for_scoped_user_auto_injects_facility(): void
    {
        $facility = Facility::factory()->create();
        $user = \App\Models\User::factory()->manager()->create(['facility_id' => $facility->id]);
        $this->actingAs($user, 'sanctum');

        // No facility_id param — the controller pulls it from the user.
        $response = $this->getJson('/api/reviews/dashboard');
        $response->assertStatus(200)
            ->assertJsonStructure(['total', 'average_rating', 'negative_review_rate', 'median_response_time_hours']);
    }
}
