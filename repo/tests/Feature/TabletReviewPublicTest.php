<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The tablet review submit flow is intentionally unauthenticated.
 * See docs/RBAC.md §6 (Notable Exceptions) and routes/api.php public block.
 * These tests pin that contract — a regression to auth-required would fail them.
 */
class TabletReviewPublicTest extends TestCase
{
    use RefreshDatabase;

    private function completedVisit(): Visit
    {
        $facility = Facility::factory()->create();
        $doctor = Doctor::factory()->create(['facility_id' => $facility->id]);
        $patient = Patient::factory()->create(['facility_id' => $facility->id]);

        return Visit::factory()->create([
            'facility_id' => $facility->id,
            'doctor_id'   => $doctor->id,
            'patient_id'  => $patient->id,
            'status'      => 'completed',
        ]);
    }

    public function test_unauthenticated_owner_can_submit_review(): void
    {
        $visit = $this->completedVisit();

        $response = $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'rating' => 5,
            'body'   => 'Kind and professional visit.',
        ]);

        $response->assertStatus(201)->assertJsonPath('rating', 5);
    }

    public function test_submitted_by_name_is_optional(): void
    {
        $visit = $this->completedVisit();

        $response = $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'rating' => 4,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('visit_reviews', [
            'visit_id'          => $visit->id,
            'rating'            => 4,
            'submitted_by_name' => null,
        ]);
    }

    public function test_owner_can_attach_up_to_five_images(): void
    {
        Storage::fake();
        $visit = $this->completedVisit();

        $files = [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
            UploadedFile::fake()->image('c.jpg'),
            UploadedFile::fake()->image('d.jpg'),
            UploadedFile::fake()->image('e.jpg'),
        ];

        $response = $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'rating' => 5,
            'images' => $files,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('review_images', 5);
    }

    public function test_more_than_five_images_is_rejected(): void
    {
        Storage::fake();
        $visit = $this->completedVisit();

        $files = [];
        for ($i = 0; $i < 6; $i++) {
            $files[] = UploadedFile::fake()->image("x{$i}.jpg");
        }

        $response = $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'rating' => 5,
            'images' => $files,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['images']);
    }

    public function test_rating_outside_1_to_5_is_rejected(): void
    {
        $visit = $this->completedVisit();

        $this->postJson("/api/reviews/visits/{$visit->id}/submit", ['rating' => 0])
            ->assertStatus(422);

        $this->postJson("/api/reviews/visits/{$visit->id}/submit", ['rating' => 6])
            ->assertStatus(422);
    }

    public function test_submit_for_nonexistent_visit_404s(): void
    {
        $this->postJson('/api/reviews/visits/999999/submit', ['rating' => 5])
            ->assertStatus(404);
    }

    public function test_route_is_reachable_without_any_bearer_token(): void
    {
        // Belt-and-braces check: even if upstream middleware were changed,
        // this asserts we never return 401 on this route.
        $visit = $this->completedVisit();
        $response = $this->withHeaders([])->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'rating' => 5,
        ]);

        $this->assertNotSame(401, $response->status(), 'Route must remain public; got 401.');
        $response->assertStatus(201);
    }
}
