<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DataVersion;
use App\Models\Facility;
use App\Models\Service;
use App\Models\ServicePricing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the services/pricing master-data surface added for the unified
 * ingestion requirement.
 */
class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_service(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/services', [
            'external_key'     => 'SVC-001',
            'name'             => 'Routine Consultation',
            'category'         => 'clinical',
            'duration_minutes' => 30,
        ]);

        $response->assertStatus(201)->assertJsonPath('external_key', 'SVC-001');
        $this->assertDatabaseHas('services', ['external_key' => 'SVC-001']);
        $this->assertGreaterThan(0, DataVersion::where('entity_type', Service::class)->count());
    }

    public function test_clerk_cannot_create_service(): void
    {
        $this->actingAsInventoryClerk();

        $this->postJson('/api/services', [
            'external_key' => 'SVC-X', 'name' => 'x', 'category' => 'clinical',
        ])->assertStatus(403);
    }

    public function test_any_authenticated_user_can_list_services(): void
    {
        $this->actingAsTechnicianDoctor();
        Service::factory()->count(3)->create();

        $this->getJson('/api/services')->assertStatus(200)->assertJsonPath('total', 3);
    }

    public function test_manager_can_set_pricing_for_own_facility(): void
    {
        $facility = Facility::factory()->create();
        $user = User::factory()->manager()->create(['facility_id' => $facility->id]);
        $this->actingAs($user, 'sanctum');

        $service = Service::factory()->create();

        $response = $this->postJson("/api/services/{$service->id}/pricings", [
            'facility_id'    => $facility->id,
            'base_price'     => 125.00,
            'effective_from' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('service_pricings', [
            'service_id' => $service->id, 'facility_id' => $facility->id,
        ]);
    }

    public function test_manager_cannot_set_pricing_for_other_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $user = User::factory()->manager()->create(['facility_id' => $a->id]);
        $this->actingAs($user, 'sanctum');

        $service = Service::factory()->create();

        $this->postJson("/api/services/{$service->id}/pricings", [
            'facility_id'    => $b->id,
            'base_price'     => 99,
            'effective_from' => now()->toDateString(),
        ])->assertStatus(403);
    }

    public function test_pricing_listing_is_scoped_to_caller_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $user = User::factory()->manager()->create(['facility_id' => $a->id]);
        $this->actingAs($user, 'sanctum');

        $service = Service::factory()->create();
        ServicePricing::factory()->create(['service_id' => $service->id, 'facility_id' => $a->id]);
        ServicePricing::factory()->create(['service_id' => $service->id, 'facility_id' => $b->id]);

        $response = $this->getJson("/api/services/{$service->id}/pricings");
        $response->assertStatus(200);
        foreach ($response->json() as $row) {
            $this->assertEquals($a->id, $row['facility_id']);
        }
    }

    public function test_services_export_is_csv(): void
    {
        $this->actingAsAdmin();
        Service::factory()->create(['name' => 'ServiceA', 'external_key' => 'SVC-A']);

        $response = $this->getJson('/api/services/export');
        $response->assertStatus(200);
        $body = $response->streamedContent();
        $this->assertStringContainsString('external_key', $body);
        $this->assertStringContainsString('SVC-A', $body);
    }

    public function test_show_service_returns_record_with_pricings(): void
    {
        $this->actingAsTechnicianDoctor();
        $facility = Facility::factory()->create();
        $service  = Service::factory()->create(['external_key' => 'SVC-SHOW-1', 'name' => 'Vaccination']);
        ServicePricing::factory()->create([
            'service_id'  => $service->id,
            'facility_id' => $facility->id,
            'base_price'  => 55.50,
        ]);

        $response = $this->getJson("/api/services/{$service->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $service->id)
            ->assertJsonPath('external_key', 'SVC-SHOW-1')
            ->assertJsonStructure(['id', 'external_key', 'name', 'pricings']);
        $this->assertCount(1, $response->json('pricings'));
    }

    public function test_show_service_returns_404_for_unknown_id(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/services/9999999')->assertStatus(404);
    }

    public function test_manager_can_update_service(): void
    {
        $this->actingAsManager();
        $service = Service::factory()->create([
            'name'     => 'Original Name',
            'category' => 'clinical',
        ]);

        $response = $this->putJson("/api/services/{$service->id}", [
            'name'             => 'Updated Name',
            'duration_minutes' => 45,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Updated Name')
            ->assertJsonPath('duration_minutes', 45);

        $this->assertDatabaseHas('services', [
            'id'               => $service->id,
            'name'             => 'Updated Name',
            'duration_minutes' => 45,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service.update']);
    }

    public function test_clerk_cannot_update_service(): void
    {
        $this->actingAsInventoryClerk();
        $service = Service::factory()->create();

        $this->putJson("/api/services/{$service->id}", [
            'name' => 'Unauthorized',
        ])->assertStatus(403);
    }

    public function test_admin_can_delete_service(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create();

        $response = $this->deleteJson("/api/services/{$service->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Service deleted.');
        $this->assertSoftDeleted('services', ['id' => $service->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service.delete']);
    }

    public function test_manager_cannot_delete_service(): void
    {
        $this->actingAsManager();
        $service = Service::factory()->create();

        $this->deleteJson("/api/services/{$service->id}")->assertStatus(403);
    }

    public function test_admin_can_import_services_via_csv(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $csv  = "external_key,name,category,code,duration_minutes\n";
        $csv .= "SVC-IMP-1,Dental Cleaning,dental,DC-100,60\n";
        $csv .= "SVC-IMP-2,Wellness Check,preventive,WC-100,30\n";

        $file = UploadedFile::fake()->createWithContent('services.csv', $csv);

        $response = $this->postJson('/api/services/import', ['file' => $file]);

        $response->assertStatus(200)->assertJsonPath('status', 'completed');
        $this->assertDatabaseHas('services', ['external_key' => 'SVC-IMP-1', 'name' => 'Dental Cleaning']);
        $this->assertDatabaseHas('services', ['external_key' => 'SVC-IMP-2', 'name' => 'Wellness Check']);
    }

    public function test_clerk_cannot_import_services(): void
    {
        Storage::fake('local');
        $this->actingAsInventoryClerk();

        $file = UploadedFile::fake()->createWithContent('services.csv', "external_key,name,category\nSVC-NO,Blocked,x\n");

        $this->postJson('/api/services/import', ['file' => $file])->assertStatus(403);
    }
}
