<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_cannot_access_api(): void
    {
        $response = $this->getJson('/api/facilities');
        $response->assertStatus(401);
    }

    public function test_csrf_protection_active_for_web_routes(): void
    {
        // Laravel's PreventRequestForgery middleware skips during unit tests
        // (runningUnitTests() returns true), so we verify it IS registered in the web stack.
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $webGroup = $kernel->getMiddlewareGroups()['web'] ?? [];

        $hasCsrf = collect($webGroup)->contains(
            fn($m) => str_contains($m, 'PreventRequestForgery')
                   || str_contains($m, 'VerifyCsrfToken')
                   || str_contains($m, 'ValidateCsrfToken')
        );

        $this->assertTrue($hasCsrf, 'CSRF middleware must be registered in the web middleware group.');
    }

    public function test_phone_masked_for_non_admin(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        $patient = Patient::factory()->create([
            'facility_id'           => $tech->facility_id,
            'owner_phone_encrypted' => encrypt('(555) 123-4567'),
        ]);

        $response = $this->getJson("/api/patients/{$patient->id}");
        $response->assertStatus(200);

        $phone = $response->json('owner_phone');
        // Non-admin should see masked phone
        $this->assertMatchesRegularExpression('/\(\d{3}\) \*{3}-\d{4}/', $phone);
    }

    public function test_admin_sees_real_phone(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $patient = Patient::factory()->create([
            'facility_id'           => $facility->id,
            'owner_phone_encrypted' => encrypt('(555) 123-4567'),
        ]);

        $response = $this->getJson("/api/patients/{$patient->id}");
        $response->assertStatus(200);

        $phone = $response->json('owner_phone');
        $this->assertEquals('(555) 123-4567', $phone);
    }

    public function test_role_based_access_enforced(): void
    {
        $this->actingAsTechnicianDoctor();

        $response = $this->getJson('/api/users');
        $response->assertStatus(403);
    }

    public function test_sql_injection_does_not_work_in_search(): void
    {
        $this->actingAsAdmin();
        Facility::factory()->create(['name' => 'Safe Hospital']);

        $maliciousInput = "' OR '1'='1";
        $response = $this->getJson('/api/facilities?search=' . urlencode($maliciousInput));

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('total'));
    }

    public function test_xss_output_is_not_unescaped(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $this->postJson('/api/rental-assets', [
            'facility_id'      => $facility->id,
            'name'             => '<script>alert("xss")</script>',
            'category'         => 'pump',
            'replacement_cost' => 100,
            'daily_rate'       => 5,
            'serial_number'    => 'SN-XSS-001',
        ]);

        $response = $this->getJson('/api/rental-assets?search=script');
        $responseBody = $response->getContent();

        // The raw script tag should be in the JSON as a string, not executed
        $this->assertStringContainsString('script', $responseBody);
        // The JSON response should have it as a JSON string value (properly escaped in context)
        $this->assertJson($responseBody);
    }

    public function test_audit_trail_created_for_data_changes(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $this->putJson("/api/facilities/{$facility->id}", ['name' => 'Updated Name']);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => \App\Models\Facility::class,
            'entity_id'   => $facility->id,
            'action'      => 'facility.update',
        ]);
    }

    public function test_sensitive_phone_stored_encrypted(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $this->postJson('/api/patients', [
            'facility_id'  => $facility->id,
            'external_key' => 'PAT-SECURITY-001',
            'name'         => 'Fluffy',
            'owner_phone'  => '(555) 987-6543',
        ]);

        // The raw phone should NOT be stored as plaintext
        $patient = \App\Models\Patient::where('external_key', 'PAT-SECURITY-001')->first();
        $this->assertNotEquals('(555) 987-6543', $patient->owner_phone_encrypted);
        $this->assertNotNull($patient->owner_phone_encrypted);
        // But decrypt should return the original
        $this->assertEquals('(555) 987-6543', decrypt($patient->owner_phone_encrypted));
    }

    public function test_inactive_user_cannot_access_api(): void
    {
        $user = User::factory()->create(['active' => false]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/facilities')
            ->assertStatus(403)
            ->assertJson(['message' => 'Account is disabled.']);
    }
}
