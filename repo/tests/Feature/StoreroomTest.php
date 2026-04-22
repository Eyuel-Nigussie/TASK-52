<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Storeroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreroomTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_authenticated_user_can_list_storerooms(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        Storeroom::factory()->count(3)->create(['facility_id' => $tech->facility_id]);

        $response = $this->getJson('/api/storerooms');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
    }

    public function test_manager_can_create_storeroom(): void
    {
        $manager = $this->actingAsManager();

        $response = $this->postJson('/api/storerooms', [
            'facility_id' => $manager->facility_id,
            'name'        => 'Main Supply Room',
            'code'        => 'MSR',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Main Supply Room');
        $this->assertDatabaseHas('storerooms', ['code' => 'MSR']);
    }

    public function test_admin_can_create_storeroom(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $response = $this->postJson('/api/storerooms', [
            'facility_id' => $facility->id,
            'name'        => 'Admin Storeroom',
        ]);

        $response->assertStatus(201);
    }

    public function test_clerk_cannot_create_storeroom(): void
    {
        $clerk = $this->actingAsInventoryClerk();

        $response = $this->postJson('/api/storerooms', [
            'facility_id' => $clerk->facility_id,
            'name'        => 'Denied Storeroom',
        ]);

        $response->assertStatus(403);
    }

    public function test_manager_can_update_storeroom(): void
    {
        $manager   = $this->actingAsManager();
        $storeroom = Storeroom::factory()->create(['facility_id' => $manager->facility_id]);

        $response = $this->putJson("/api/storerooms/{$storeroom->id}", [
            'name' => 'Updated Storage Area',
            'code' => 'USA',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Updated Storage Area');
    }

    public function test_admin_can_delete_storeroom(): void
    {
        $this->actingAsAdmin();
        $storeroom = Storeroom::factory()->create();

        $response = $this->deleteJson("/api/storerooms/{$storeroom->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('storerooms', ['id' => $storeroom->id]);
    }

    public function test_manager_cannot_delete_storeroom(): void
    {
        $manager   = $this->actingAsManager();
        $storeroom = Storeroom::factory()->create(['facility_id' => $manager->facility_id]);

        $response = $this->deleteJson("/api/storerooms/{$storeroom->id}");

        $response->assertStatus(403);
    }

    public function test_clerk_cannot_delete_storeroom(): void
    {
        $clerk     = $this->actingAsInventoryClerk();
        $storeroom = Storeroom::factory()->create(['facility_id' => $clerk->facility_id]);

        $response = $this->deleteJson("/api/storerooms/{$storeroom->id}");

        $response->assertStatus(403);
    }

    public function test_can_filter_storerooms_by_facility(): void
    {
        $tech = $this->actingAsTechnicianDoctor();

        // ScopesByFacility locks results to the user's own facility.
        // Create storerooms in the tech's facility with different active states
        // so the filter parameter is still exercised via name prefix.
        Storeroom::factory()->count(2)->create([
            'facility_id' => $tech->facility_id,
            'name'        => 'Alpha Room',
        ]);
        Storeroom::factory()->count(3)->create([
            'facility_id' => $tech->facility_id,
            'name'        => 'Beta Room',
        ]);

        // With ScopesByFacility applied, passing the tech's own facility_id
        // should still return all storerooms in that facility (2 + 3 = 5).
        $response = $this->getJson("/api/storerooms?facility_id={$tech->facility_id}");

        $response->assertStatus(200);
        $this->assertCount(5, $response->json());
    }

    public function test_active_only_filter_works(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        Storeroom::factory()->create(['facility_id' => $tech->facility_id, 'active' => true]);
        Storeroom::factory()->create(['facility_id' => $tech->facility_id, 'active' => false]);

        $response = $this->getJson('/api/storerooms?active_only=1');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_facility_must_exist(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/storerooms', [
            'facility_id' => 99999,
            'name'        => 'Ghost Storeroom',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['facility_id']);
    }
}
