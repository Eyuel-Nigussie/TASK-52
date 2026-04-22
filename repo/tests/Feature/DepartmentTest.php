<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Facility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_departments(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        Department::factory()->count(3)->create(['facility_id' => $tech->facility_id]);

        $response = $this->getJson('/api/departments');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
    }

    public function test_can_filter_departments_by_facility(): void
    {
        $this->actingAsAdmin();
        $facility1 = Facility::factory()->create();
        $facility2 = Facility::factory()->create();
        Department::factory()->count(2)->create(['facility_id' => $facility1->id]);
        Department::factory()->count(1)->create(['facility_id' => $facility2->id]);

        $response = $this->getJson("/api/departments?facility_id={$facility1->id}");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    public function test_admin_can_create_department(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $response = $this->postJson('/api/departments', [
            'facility_id'  => $facility->id,
            'external_key' => 'DEPT-001',
            'name'         => 'Surgery',
            'code'         => 'SRG',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Surgery');
        $this->assertDatabaseHas('departments', ['code' => 'SRG']);
    }

    public function test_manager_can_create_department(): void
    {
        $manager = $this->actingAsManager();

        $response = $this->postJson('/api/departments', [
            'facility_id'  => $manager->facility_id,
            'external_key' => 'DEPT-002',
            'name'         => 'Emergency',
        ]);

        $response->assertStatus(201);
    }

    public function test_clerk_cannot_create_department(): void
    {
        $this->actingAsInventoryClerk();
        $facility = Facility::factory()->create();

        $response = $this->postJson('/api/departments', [
            'facility_id'  => $facility->id,
            'external_key' => 'DEPT-003',
            'name'         => 'Denied Dept',
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_update_department(): void
    {
        $this->actingAsAdmin();
        $dept = Department::factory()->create();

        $response = $this->putJson("/api/departments/{$dept->id}", [
            'name' => 'Renamed Department',
            'code' => 'RNM',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Renamed Department');
    }

    public function test_admin_can_patch_department(): void
    {
        $this->actingAsAdmin();
        $dept = Department::factory()->create(['name' => 'Original', 'active' => true]);

        $response = $this->patchJson("/api/departments/{$dept->id}", [
            'name'   => 'Patched Department',
            'active' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Patched Department')
            ->assertJsonPath('active', false);

        $this->assertDatabaseHas('departments', [
            'id'     => $dept->id,
            'name'   => 'Patched Department',
            'active' => false,
        ]);
    }

    public function test_clerk_cannot_patch_department(): void
    {
        $this->actingAsInventoryClerk();
        $dept = Department::factory()->create();

        $response = $this->patchJson("/api/departments/{$dept->id}", [
            'name' => 'Nope',
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_delete_department(): void
    {
        $this->actingAsAdmin();
        $dept = Department::factory()->create();

        $response = $this->deleteJson("/api/departments/{$dept->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('departments', ['id' => $dept->id]);
    }

    public function test_clerk_cannot_delete_department(): void
    {
        $this->actingAsInventoryClerk();
        $dept = Department::factory()->create();

        $response = $this->deleteJson("/api/departments/{$dept->id}");

        $response->assertStatus(403);
    }

    public function test_active_only_filter_works(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        Department::factory()->create(['active' => true, 'facility_id' => $tech->facility_id]);
        Department::factory()->create(['active' => false, 'facility_id' => $tech->facility_id]);

        $response = $this->getJson('/api/departments?active_only=1');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_facility_id_must_exist(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/departments', [
            'facility_id'  => 99999,
            'external_key' => 'DEPT-999',
            'name'         => 'Ghost Dept',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['facility_id']);
    }
}
