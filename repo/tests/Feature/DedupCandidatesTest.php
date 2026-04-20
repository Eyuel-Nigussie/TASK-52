<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\RentalAsset;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dedup candidates API surfaces key-field near-duplicates for manager review
 * before merge-requests are created. The endpoint must group records that
 * share discriminating fields (name+facility for doctors/patients,
 * name+category for services) into candidate groups.
 */
class DedupCandidatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_doctor_candidates_with_matching_name_and_facility(): void
    {
        $facility = Facility::factory()->create();
        Doctor::factory()->create(['first_name' => 'Jane', 'last_name' => 'Smith', 'facility_id' => $facility->id]);
        Doctor::factory()->create(['first_name' => 'Jane', 'last_name' => 'Smith', 'facility_id' => $facility->id]);
        Doctor::factory()->create(['first_name' => 'John', 'last_name' => 'Unique', 'facility_id' => $facility->id]);

        $this->actingAsAdmin();
        $response = $this->getJson('/api/dedup/candidates?entity_type=doctor');

        $response->assertStatus(200);
        $groups = $response->json('groups');
        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups[0]['records']);
        $this->assertEquals('Jane', $groups[0]['key_fields']['first_name']);
        $this->assertEquals('Smith', $groups[0]['key_fields']['last_name']);
    }

    public function test_returns_patient_candidates_with_matching_name_species_and_facility(): void
    {
        $facility = Facility::factory()->create();
        Patient::factory()->create(['name' => 'Buddy', 'species' => 'dog', 'facility_id' => $facility->id]);
        Patient::factory()->create(['name' => 'Buddy', 'species' => 'dog', 'facility_id' => $facility->id]);
        Patient::factory()->create(['name' => 'Buddy', 'species' => 'cat', 'facility_id' => $facility->id]);

        $this->actingAsAdmin();
        $response = $this->getJson('/api/dedup/candidates?entity_type=patient');

        $response->assertStatus(200);
        $groups = $response->json('groups');
        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups[0]['records']);
        $this->assertEquals('Buddy', $groups[0]['key_fields']['name']);
        $this->assertEquals('dog', $groups[0]['key_fields']['species']);
    }

    public function test_returns_service_candidates_with_matching_name_and_category(): void
    {
        Service::factory()->create(['name' => 'Dental Clean', 'category' => 'dental']);
        Service::factory()->create(['name' => 'Dental Clean', 'category' => 'dental']);
        Service::factory()->create(['name' => 'Vaccination', 'category' => 'preventive']);

        $this->actingAsAdmin();
        $response = $this->getJson('/api/dedup/candidates?entity_type=service');

        $response->assertStatus(200);
        $groups = $response->json('groups');
        $this->assertCount(1, $groups);
        $this->assertEquals('Dental Clean', $groups[0]['key_fields']['name']);
    }

    public function test_returns_empty_when_no_duplicates(): void
    {
        $facility = Facility::factory()->create();
        Doctor::factory()->create(['first_name' => 'Alice', 'last_name' => 'Jones', 'facility_id' => $facility->id]);
        Doctor::factory()->create(['first_name' => 'Bob', 'last_name' => 'Brown', 'facility_id' => $facility->id]);

        $this->actingAsAdmin();
        $response = $this->getJson('/api/dedup/candidates?entity_type=doctor');

        $response->assertStatus(200)->assertJson(['total_groups' => 0]);
    }

    public function test_facility_id_filter_scopes_doctor_candidates(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();

        // Duplicate at facility A
        Doctor::factory()->create(['first_name' => 'Sam', 'last_name' => 'Lee', 'facility_id' => $a->id]);
        Doctor::factory()->create(['first_name' => 'Sam', 'last_name' => 'Lee', 'facility_id' => $a->id]);

        // Duplicate at facility B (should not appear when filtering by A)
        Doctor::factory()->create(['first_name' => 'Sam', 'last_name' => 'Lee', 'facility_id' => $b->id]);
        Doctor::factory()->create(['first_name' => 'Sam', 'last_name' => 'Lee', 'facility_id' => $b->id]);

        $this->actingAsAdmin();
        $response = $this->getJson('/api/dedup/candidates?entity_type=doctor&facility_id=' . $a->id);

        $response->assertStatus(200);
        $groups = $response->json('groups');
        $this->assertCount(1, $groups);
        $this->assertEquals($a->id, $groups[0]['key_fields']['facility_id']);
    }

    public function test_manager_can_access_candidates(): void
    {
        $facility = Facility::factory()->create();
        $this->actingAsManagerOf($facility);

        $response = $this->getJson('/api/dedup/candidates?entity_type=service');
        $response->assertStatus(200);
    }

    public function test_facility_scoped_manager_cannot_query_other_facility_candidates(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();

        // Duplicate pair exists at facility B only
        Doctor::factory()->create(['first_name' => 'Eve', 'last_name' => 'Knox', 'facility_id' => $b->id]);
        Doctor::factory()->create(['first_name' => 'Eve', 'last_name' => 'Knox', 'facility_id' => $b->id]);

        $this->actingAsManagerOf($a);

        $explicit = $this->getJson('/api/dedup/candidates?entity_type=doctor&facility_id=' . $b->id);
        $explicit->assertStatus(403);

        // When no facility_id is provided, the scope is pinned to the manager's
        // own facility — they must not leak facility B's duplicates.
        $implicit = $this->getJson('/api/dedup/candidates?entity_type=doctor');
        $implicit->assertStatus(200);
        $this->assertSame(0, $implicit->json('total_groups'));
    }

    public function test_inventory_clerk_cannot_access_candidates(): void
    {
        $this->actingAsInventoryClerk();

        $this->getJson('/api/dedup/candidates?entity_type=service')->assertStatus(403);
    }

    public function test_unsupported_entity_type_returns_422(): void
    {
        $this->actingAsAdmin();
        $this->getJson('/api/dedup/candidates?entity_type=facility')->assertStatus(422);
    }

    private function actingAsManagerOf(Facility $facility): void
    {
        $user = \App\Models\User::factory()->manager()->create(['facility_id' => $facility->id]);
        $this->actingAs($user, 'sanctum');
    }
}
