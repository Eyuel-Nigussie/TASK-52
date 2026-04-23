<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DataVersion;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\Patient;
use App\Models\RentalAsset;
use App\Models\Storeroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Every change reversible" — for core entities we expect a DataVersion
 * snapshot on create and on update, so we can revert later.
 */
class DataVersioningCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_create_and_update_are_versioned(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $resp = $this->postJson('/api/doctors', [
            'facility_id'  => $facility->id,
            'external_key' => 'DOC-V1',
            'first_name'   => 'Ada',
            'last_name'    => 'Lovelace',
        ])->assertStatus(201);

        $doctorId = $resp->json('id');
        $this->assertEquals(1, DataVersion::where('entity_type', Doctor::class)->where('entity_id', $doctorId)->count());

        $this->putJson("/api/doctors/{$doctorId}", ['specialty' => 'Surgery'])->assertStatus(200);
        $this->assertEquals(2, DataVersion::where('entity_type', Doctor::class)->where('entity_id', $doctorId)->count());
    }

    public function test_patient_create_and_update_are_versioned(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $resp = $this->postJson('/api/patients', [
            'facility_id'  => $facility->id,
            'external_key' => 'PAT-V1',
            'name'         => 'Rex',
        ])->assertStatus(201);

        $patientId = $resp->json('id');
        $this->assertEquals(1, DataVersion::where('entity_type', Patient::class)->where('entity_id', $patientId)->count());

        $this->putJson("/api/patients/{$patientId}", ['breed' => 'Labrador'])->assertStatus(200);
        $this->assertEquals(2, DataVersion::where('entity_type', Patient::class)->where('entity_id', $patientId)->count());
    }

    public function test_rental_asset_create_and_update_are_versioned(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $resp = $this->postJson('/api/rental-assets', [
            'facility_id'      => $facility->id,
            'name'             => 'Ultrasound',
            'category'         => 'imaging',
            'replacement_cost' => 2000,
            'daily_rate'       => 40,
        ])->assertStatus(201);

        $assetId = $resp->json('id');
        $this->assertEquals(1, DataVersion::where('entity_type', RentalAsset::class)->where('entity_id', $assetId)->count());

        $this->putJson("/api/rental-assets/{$assetId}", ['daily_rate' => 60])->assertStatus(200);
        $this->assertEquals(2, DataVersion::where('entity_type', RentalAsset::class)->where('entity_id', $assetId)->count());
    }

    public function test_inventory_item_create_and_update_are_versioned(): void
    {
        $this->actingAsAdmin();

        $resp = $this->postJson('/api/inventory/items', [
            'external_key' => 'INV-V1',
            'name'         => 'Gauze',
            'category'     => 'consumable',
        ])->assertStatus(201);

        $itemId = $resp->json('id');
        $this->assertEquals(1, DataVersion::where('entity_type', InventoryItem::class)->where('entity_id', $itemId)->count());

        $this->putJson("/api/inventory/items/{$itemId}", ['category' => 'sterile'])->assertStatus(200);
        $this->assertEquals(2, DataVersion::where('entity_type', InventoryItem::class)->where('entity_id', $itemId)->count());
    }

    public function test_department_update_is_versioned(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $resp = $this->postJson('/api/departments', [
            'facility_id'  => $facility->id,
            'external_key' => 'DEPT-V1',
            'name'         => 'Cardiology',
        ])->assertStatus(201);

        $deptId = $resp->json('id');

        $this->putJson("/api/departments/{$deptId}", ['name' => 'Neurology'])->assertStatus(200);
        $this->assertEquals(
            1,
            DataVersion::where('entity_type', Department::class)->where('entity_id', $deptId)->count(),
            'update must create a DataVersion snapshot'
        );
    }

    public function test_storeroom_update_is_versioned(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $resp = $this->postJson('/api/storerooms', [
            'facility_id' => $facility->id,
            'name'        => 'Room A',
        ])->assertStatus(201);

        $storeroomId = $resp->json('id');

        $this->putJson("/api/storerooms/{$storeroomId}", ['name' => 'Room B'])->assertStatus(200);
        $this->assertEquals(
            1,
            DataVersion::where('entity_type', Storeroom::class)->where('entity_id', $storeroomId)->count(),
            'update must create a DataVersion snapshot'
        );
    }

    public function test_user_update_is_versioned(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $resp = $this->postJson('/api/users', [
            'username'              => 'ver_user',
            'name'                  => 'Version Test',
            'password'              => 'P@ssword123!',
            'password_confirmation' => 'P@ssword123!',
            'role'                  => 'inventory_clerk',
            'facility_id'           => $facility->id,
        ])->assertStatus(201);

        $userId = $resp->json('id');

        $this->putJson("/api/users/{$userId}", ['name' => 'Updated Name'])->assertStatus(200);
        $this->assertEquals(
            1,
            DataVersion::where('entity_type', User::class)->where('entity_id', $userId)->count(),
            'update must create a DataVersion snapshot'
        );
    }

    public function test_data_version_can_revert_entity(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $patient = Patient::factory()->create(['facility_id' => $facility->id, 'name' => 'Original']);

        app(\App\Services\DataVersioningService::class)
            ->record($patient, [], 1, 'Initial');

        $patient->update(['name' => 'Changed']);
        app(\App\Services\DataVersioningService::class)
            ->record($patient, ['name' => 'Original'], 1, 'Edited');

        app(\App\Services\DataVersioningService::class)->revert($patient->fresh(), 1, 1);

        $this->assertEquals('Original', $patient->fresh()->name);
    }
}
