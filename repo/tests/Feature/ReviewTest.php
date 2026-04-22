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

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private function createCompletedVisit(?int $facilityId = null): Visit
    {
        $facility = $facilityId !== null ? Facility::findOrFail($facilityId) : Facility::factory()->create();
        $doctor = Doctor::factory()->create(['facility_id' => $facility->id]);
        $patient = Patient::factory()->create(['facility_id' => $facility->id]);

        return Visit::factory()->create([
            'facility_id' => $facility->id,
            'doctor_id'   => $doctor->id,
            'patient_id'  => $patient->id,
            'status'      => 'completed',
        ]);
    }

    public function test_can_submit_review_for_completed_visit(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        $visit = $this->createCompletedVisit($tech->facility_id);

        $response = $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'rating'            => 5,
            'body'              => 'Excellent care for our pet.',
            'tags'              => ['professional', 'caring'],
            'submitted_by_name' => 'John Owner',
        ]);

        $response->assertStatus(201)->assertJsonPath('rating', 5);
    }

    public function test_cannot_submit_review_for_non_completed_visit(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        $facility = Facility::findOrFail($tech->facility_id);
        $doctor = Doctor::factory()->create(['facility_id' => $facility->id]);
        $patient = Patient::factory()->create(['facility_id' => $facility->id]);

        $visit = Visit::factory()->create([
            'facility_id' => $facility->id,
            'doctor_id'   => $doctor->id,
            'patient_id'  => $patient->id,
            'status'      => 'scheduled',
        ]);

        $response = $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'rating'            => 4,
            'submitted_by_name' => 'Owner',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_submit_duplicate_review(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        $visit = $this->createCompletedVisit($tech->facility_id);

        $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'rating'            => 4,
            'submitted_by_name' => 'Owner',
        ]);

        $response = $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'rating'            => 3,
            'submitted_by_name' => 'Owner',
        ]);

        $response->assertStatus(422);
    }

    public function test_manager_can_publish_review(): void
    {
        $manager = $this->actingAsManager();
        $visit = $this->createCompletedVisit($manager->facility_id);
        $review = VisitReview::factory()->create([
            'visit_id'    => $visit->id,
            'facility_id' => $manager->facility_id,
            'doctor_id'   => $visit->doctor_id,
            'status'      => 'pending',
        ]);

        $response = $this->postJson("/api/reviews/{$review->id}/publish");
        $response->assertStatus(200)->assertJsonPath('status', 'published');
    }

    public function test_manager_can_hide_review(): void
    {
        $manager = $this->actingAsManager();
        $visit = $this->createCompletedVisit($manager->facility_id);
        $review = VisitReview::factory()->create([
            'visit_id'    => $visit->id,
            'facility_id' => $manager->facility_id,
            'doctor_id'   => $visit->doctor_id,
            'status'      => 'published',
        ]);

        $response = $this->postJson("/api/reviews/{$review->id}/hide", [
            'reason' => 'This review contains abusive language and violates our policy.',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 'hidden');
    }

    public function test_manager_can_respond_to_review(): void
    {
        $manager = $this->actingAsManager();
        $visit = $this->createCompletedVisit($manager->facility_id);
        $review = VisitReview::factory()->create([
            'visit_id'    => $visit->id,
            'facility_id' => $manager->facility_id,
            'doctor_id'   => $visit->doctor_id,
            'status'      => 'published',
        ]);

        $response = $this->postJson("/api/reviews/{$review->id}/respond", [
            'body' => 'Thank you for your feedback! We strive to provide the best care.',
        ]);

        $response->assertStatus(200)->assertJsonPath('body', 'Thank you for your feedback! We strive to provide the best care.');
    }

    public function test_can_appeal_review(): void
    {
        $manager = $this->actingAsManager();
        $visit = $this->createCompletedVisit($manager->facility_id);
        $review = VisitReview::factory()->create([
            'visit_id'    => $visit->id,
            'facility_id' => $manager->facility_id,
            'doctor_id'   => $visit->doctor_id,
            'status'      => 'published',
        ]);

        $response = $this->postJson("/api/reviews/{$review->id}/appeal", [
            'reason' => 'This review appears to be fraudulent and violates our terms of service.',
        ]);

        $response->assertStatus(201)->assertJsonPath('status', 'open');
        $this->assertDatabaseHas('visit_reviews', ['id' => $review->id, 'status' => 'appealed']);
    }

    public function test_rating_must_be_between_1_and_5(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        $visit = $this->createCompletedVisit($tech->facility_id);

        $response = $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'rating'            => 6,
            'submitted_by_name' => 'Owner',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['rating']);
    }

    public function test_dashboard_returns_statistics(): void
    {
        $manager = $this->actingAsManager();
        $facilityId = $manager->facility_id;
        $doctor = Doctor::factory()->create(['facility_id' => $facilityId]);
        $patient = Patient::factory()->create(['facility_id' => $facilityId]);

        for ($i = 0; $i < 5; $i++) {
            $visit = Visit::factory()->create([
                'facility_id' => $facilityId,
                'doctor_id'   => $doctor->id,
                'patient_id'  => $patient->id,
                'status'      => 'completed',
            ]);
            VisitReview::factory()->create([
                'visit_id'    => $visit->id,
                'facility_id' => $facilityId,
                'doctor_id'   => $doctor->id,
                'status'      => 'published',
                'rating'      => rand(1, 5),
            ]);
        }

        $response = $this->getJson("/api/reviews/dashboard?facility_id={$facilityId}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total',
                'average_rating',
                'negative_review_rate',
                'median_response_time_hours',
            ]);
    }

    public function test_can_list_reviews_with_filters(): void
    {
        $manager = $this->actingAsManager();
        $facilityId = $manager->facility_id;

        // Create two reviews in the manager's facility; one review in an unrelated facility.
        VisitReview::factory()->count(2)->create(['facility_id' => $facilityId]);
        VisitReview::factory()->count(1)->create(); // different facility — not visible to manager

        $response = $this->getJson('/api/reviews');

        $response->assertStatus(200)
            ->assertJsonPath('total', 2);
    }

    public function test_can_filter_reviews_by_rating(): void
    {
        $manager = $this->actingAsManager();
        $facilityId = $manager->facility_id;

        VisitReview::factory()->count(2)->create(['facility_id' => $facilityId, 'rating' => 5]);
        VisitReview::factory()->count(1)->create(['facility_id' => $facilityId, 'rating' => 2]);

        $response = $this->getJson('/api/reviews?rating=2');

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_can_show_review_with_relationships(): void
    {
        $manager = $this->actingAsManager();
        $visit = $this->createCompletedVisit($manager->facility_id);
        $review = VisitReview::factory()->create([
            'visit_id'    => $visit->id,
            'facility_id' => $manager->facility_id,
            'doctor_id'   => $visit->doctor_id,
            'status'      => 'published',
        ]);

        $response = $this->getJson("/api/reviews/{$review->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $review->id)
            ->assertJsonStructure(['images', 'doctor']);
    }

    public function test_technician_cannot_publish_review(): void
    {
        $this->actingAsTechnicianDoctor();
        $review = VisitReview::factory()->create(['status' => 'pending']);

        $response = $this->postJson("/api/reviews/{$review->id}/publish");

        $response->assertStatus(403);
    }

    public function test_technician_cannot_hide_review(): void
    {
        $this->actingAsTechnicianDoctor();
        $review = VisitReview::factory()->create(['status' => 'published']);

        $response = $this->postJson("/api/reviews/{$review->id}/hide", [
            'reason' => 'Attempting to hide review without permission.',
        ]);

        $response->assertStatus(403);
    }

    public function test_technician_cannot_respond_to_review(): void
    {
        $this->actingAsTechnicianDoctor();
        $review = VisitReview::factory()->create(['status' => 'published']);

        $response = $this->postJson("/api/reviews/{$review->id}/respond", [
            'body' => 'Attempting unauthorized response.',
        ]);

        $response->assertStatus(403);
    }

    public function test_hide_requires_reason_minimum_length(): void
    {
        $manager = $this->actingAsManager();
        $visit = $this->createCompletedVisit($manager->facility_id);
        $review = VisitReview::factory()->create([
            'visit_id'    => $visit->id,
            'facility_id' => $manager->facility_id,
            'doctor_id'   => $visit->doctor_id,
            'status'      => 'published',
        ]);

        $response = $this->postJson("/api/reviews/{$review->id}/hide", [
            'reason' => 'short',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    public function test_manager_can_resolve_appeal(): void
    {
        $manager = $this->actingAsManager();
        $visit = $this->createCompletedVisit($manager->facility_id);
        $review = VisitReview::factory()->create([
            'visit_id'    => $visit->id,
            'facility_id' => $manager->facility_id,
            'doctor_id'   => $visit->doctor_id,
            'status'      => 'published',
        ]);

        $appealResp = $this->postJson("/api/reviews/{$review->id}/appeal", [
            'reason' => 'Customer claims review contains fabricated details.',
        ]);
        $appealId = $appealResp->json('id');

        $response = $this->postJson("/api/reviews/appeals/{$appealId}/resolve", [
            'resolution' => 'After investigation, the review has been verified as authentic.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('review_appeals', ['id' => $appealId, 'status' => 'resolved']);
    }

    public function test_dashboard_requires_facility_id(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/reviews/dashboard');

        $response->assertStatus(422)->assertJsonValidationErrors(['facility_id']);
    }

    public function test_review_body_character_limit_enforced(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        $visit = $this->createCompletedVisit($tech->facility_id);

        $response = $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'rating'            => 5,
            'body'              => str_repeat('a', 5001),
            'submitted_by_name' => 'Verbose Owner',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['body']);
    }
}
