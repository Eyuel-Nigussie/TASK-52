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
            'facility_id'  => $facility->id,
            'doctor_id'    => $doctor->id,
            'patient_id'   => $patient->id,
            'status'       => 'completed',
            'review_token' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    public function test_unauthenticated_owner_can_submit_review(): void
    {
        $visit = $this->completedVisit();
        $token = $visit->getRawOriginal('review_token') ?? $visit->getAttributes()['review_token'];

        $response = $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'review_token' => $token,
            'rating'       => 5,
            'body'         => 'Kind and professional visit.',
        ]);

        $response->assertStatus(201)->assertJsonPath('rating', 5);
    }

    public function test_submitted_by_name_is_optional(): void
    {
        $visit = $this->completedVisit();
        $token = $visit->getAttributes()['review_token'];

        $response = $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'review_token' => $token,
            'rating'       => 4,
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
        $token = $visit->getAttributes()['review_token'];

        $files = [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
            UploadedFile::fake()->image('c.jpg'),
            UploadedFile::fake()->image('d.jpg'),
            UploadedFile::fake()->image('e.jpg'),
        ];

        $response = $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'review_token' => $token,
            'rating'       => 5,
            'images'       => $files,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('review_images', 5);
    }

    public function test_more_than_five_images_is_rejected(): void
    {
        Storage::fake();
        $visit = $this->completedVisit();
        $token = $visit->getAttributes()['review_token'];

        $files = [];
        for ($i = 0; $i < 6; $i++) {
            $files[] = UploadedFile::fake()->image("x{$i}.jpg");
        }

        $response = $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'review_token' => $token,
            'rating'       => 5,
            'images'       => $files,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['images']);
    }

    public function test_rating_outside_1_to_5_is_rejected(): void
    {
        $visit = $this->completedVisit();
        $token = $visit->getAttributes()['review_token'];

        $this->postJson("/api/reviews/visits/{$visit->id}/submit", ['review_token' => $token, 'rating' => 0])
            ->assertStatus(422);

        // Token was not consumed (validation rejected before token check reaches pass), create a new visit for 2nd assertion
        $visit2 = $this->completedVisit();
        $token2 = $visit2->getAttributes()['review_token'];
        $this->postJson("/api/reviews/visits/{$visit2->id}/submit", ['review_token' => $token2, 'rating' => 6])
            ->assertStatus(422);
    }

    public function test_submit_for_nonexistent_visit_404s(): void
    {
        $this->postJson('/api/reviews/visits/999999/submit', ['review_token' => 'any', 'rating' => 5])
            ->assertStatus(404);
    }

    public function test_submit_with_invalid_token_is_rejected(): void
    {
        $visit = $this->completedVisit();

        $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'review_token' => 'wrong-token-value',
            'rating'       => 5,
        ])->assertStatus(403);
    }

    public function test_token_is_consumed_after_first_use(): void
    {
        $visit = $this->completedVisit();
        $token = $visit->getAttributes()['review_token'];

        $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'review_token' => $token,
            'rating'       => 5,
        ])->assertStatus(201);

        // Second attempt with the same token must fail — token was nullified.
        $this->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'review_token' => $token,
            'rating'       => 4,
        ])->assertStatus(403);
    }

    public function test_route_is_reachable_without_any_bearer_token(): void
    {
        $visit = $this->completedVisit();
        $token = $visit->getAttributes()['review_token'];
        $response = $this->withHeaders([])->postJson("/api/reviews/visits/{$visit->id}/submit", [
            'review_token' => $token,
            'rating'       => 5,
        ]);

        $this->assertNotSame(401, $response->status(), 'Route must remain public; got 401.');
        $response->assertStatus(201);
    }
}
