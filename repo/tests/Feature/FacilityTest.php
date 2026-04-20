<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Facility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_facilities(): void
    {
        $this->actingAsTechnicianDoctor();
        Facility::factory()->count(3)->create();

        $response = $this->getJson('/api/facilities');

        $response->assertStatus(200)
            ->assertJsonPath('total', 3);
    }

    public function test_unauthenticated_user_cannot_list_facilities(): void
    {
        $response = $this->getJson('/api/facilities');
        $response->assertStatus(401);
    }

    public function test_admin_can_create_facility(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/facilities', [
            'external_key' => 'FAC-0001',
            'name'         => 'Main Street Vet',
            'address'      => '123 Main St',
            'city'         => 'Springfield',
            'state'        => 'IL',
            'zip'          => '62701',
            'email'        => 'info@mainstreetvet.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Main Street Vet');
        $this->assertDatabaseHas('facilities', ['external_key' => 'FAC-0001']);
    }

    public function test_manager_can_create_facility(): void
    {
        $this->actingAsManager();

        $response = $this->postJson('/api/facilities', [
            'external_key' => 'FAC-0002',
            'name'         => 'Downtown Animal Clinic',
            'address'      => '456 Oak Ave',
            'city'         => 'Chicago',
            'state'        => 'IL',
            'zip'          => '60601',
        ]);

        $response->assertStatus(201);
    }

    public function test_clerk_cannot_create_facility(): void
    {
        $this->actingAsInventoryClerk();

        $response = $this->postJson('/api/facilities', [
            'external_key' => 'FAC-0003',
            'name'         => 'Test Facility',
            'address'      => '789 Pine Rd',
            'city'         => 'Rockford',
            'state'        => 'IL',
            'zip'          => '61101',
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_sees_masked_phone(): void
    {
        $this->actingAsManager();
        $facility = Facility::factory()->create(['phone_encrypted' => encrypt('(555) 123-4567')]);

        $response = $this->getJson("/api/facilities/{$facility->id}");

        $response->assertStatus(200);
        $this->assertStringContainsString('*', $response->json('phone'));
    }

    public function test_admin_sees_full_phone(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create(['phone_encrypted' => encrypt('(555) 123-4567')]);

        $response = $this->getJson("/api/facilities/{$facility->id}");

        $response->assertStatus(200)
            ->assertJsonPath('phone', '(555) 123-4567');
    }

    public function test_admin_can_update_facility(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $response = $this->putJson("/api/facilities/{$facility->id}", [
            'name' => 'Updated Vet Clinic',
            'city' => 'Naperville',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Updated Vet Clinic')
            ->assertJsonPath('city', 'Naperville');
    }

    public function test_admin_can_delete_facility(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $response = $this->deleteJson("/api/facilities/{$facility->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('facilities', ['id' => $facility->id]);
    }

    public function test_manager_cannot_delete_facility(): void
    {
        $this->actingAsManager();
        $facility = Facility::factory()->create();

        $response = $this->deleteJson("/api/facilities/{$facility->id}");

        $response->assertStatus(403);
    }

    public function test_duplicate_external_key_is_rejected(): void
    {
        $this->actingAsAdmin();
        Facility::factory()->create(['external_key' => 'FAC-DUPE']);

        $response = $this->postJson('/api/facilities', [
            'external_key' => 'FAC-DUPE',
            'name'         => 'Another Vet',
            'address'      => '1 Other St',
            'city'         => 'Aurora',
            'state'        => 'IL',
            'zip'          => '60505',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['external_key']);
    }

    public function test_search_by_name_filters_results(): void
    {
        $this->actingAsTechnicianDoctor();
        Facility::factory()->create(['name' => 'Sunrise Veterinary Hospital']);
        Facility::factory()->create(['name' => 'Downtown Pet Clinic']);

        $response = $this->getJson('/api/facilities?search=Sunrise');

        $response->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Sunrise Veterinary Hospital');
    }

    public function test_active_only_filter_excludes_inactive(): void
    {
        $this->actingAsTechnicianDoctor();
        Facility::factory()->create(['active' => true]);
        Facility::factory()->create(['active' => false]);

        $response = $this->getJson('/api/facilities?active_only=1');

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_show_loads_departments(): void
    {
        $this->actingAsTechnicianDoctor();
        $facility = Facility::factory()->create();
        Department::factory()->create(['facility_id' => $facility->id]);

        $response = $this->getJson("/api/facilities/{$facility->id}");

        $response->assertStatus(200);
        $this->assertArrayHasKey('departments', $response->json());
    }

    public function test_facility_history_returns_versions(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        // Update facility to generate a version
        $this->putJson("/api/facilities/{$facility->id}", ['name' => 'Updated Name']);

        $response = $this->getJson("/api/facilities/{$facility->id}/history");

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_state_must_be_two_characters(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/facilities', [
            'external_key' => 'FAC-0099',
            'name'         => 'Test',
            'address'      => '1 Test St',
            'city'         => 'TestCity',
            'state'        => 'Illinois',
            'zip'          => '62000',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['state']);
    }
}
