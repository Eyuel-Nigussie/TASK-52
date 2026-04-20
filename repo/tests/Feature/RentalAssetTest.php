<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_rental_assets(): void
    {
        $this->actingAsAdmin();
        RentalAsset::factory()->count(3)->create();

        $response = $this->getJson('/api/rental-assets');
        $response->assertStatus(200)->assertJsonStructure(['data', 'total']);
    }

    public function test_can_create_rental_asset(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $response = $this->postJson('/api/rental-assets', [
            'facility_id'      => $facility->id,
            'name'             => 'Infusion Pump Model X',
            'category'         => 'infusion_pump',
            'replacement_cost' => 5000.00,
            'daily_rate'       => 50.00,
            'weekly_rate'      => 280.00,
            'serial_number'    => 'SN-12345678',
            'barcode'          => '1234567890123',
        ]);

        $response->assertStatus(201)->assertJsonPath('name', 'Infusion Pump Model X');
        $this->assertDatabaseHas('rental_assets', ['name' => 'Infusion Pump Model X']);
    }

    public function test_deposit_auto_calculated_on_create(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $response = $this->postJson('/api/rental-assets', [
            'facility_id'      => $facility->id,
            'name'             => 'Test Asset',
            'category'         => 'oxygen_cage',
            'replacement_cost' => 1000.00,
            'daily_rate'       => 20.00,
            'serial_number'    => 'SN-UNIQUE-001',
        ]);

        $response->assertStatus(201);
        // 20% of 1000 = 200, which is > 50 min deposit
        $this->assertEquals(200.00, (float) $response->json('deposit_amount'));
    }

    public function test_deposit_minimum_enforced(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $response = $this->postJson('/api/rental-assets', [
            'facility_id'      => $facility->id,
            'name'             => 'Cheap Asset',
            'category'         => 'other',
            'replacement_cost' => 100.00,  // 20% = 20, but min is 50
            'daily_rate'       => 5.00,
            'serial_number'    => 'SN-CHEAP-001',
        ]);

        $response->assertStatus(201);
        $this->assertEquals(50.00, (float) $response->json('deposit_amount'));
    }

    public function test_can_filter_assets_by_status(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        RentalAsset::factory()->create(['facility_id' => $facility->id, 'status' => 'available']);
        RentalAsset::factory()->create(['facility_id' => $facility->id, 'status' => 'in_maintenance']);

        $response = $this->getJson('/api/rental-assets?status=available');
        $response->assertStatus(200);
        $response->assertJsonPath('total', 1);
    }

    public function test_can_lookup_asset_by_barcode(): void
    {
        $this->actingAsAdmin();
        $asset = RentalAsset::factory()->create(['barcode' => '9876543210123']);

        $response = $this->getJson('/api/rental-assets/scan?code=9876543210123');
        $response->assertStatus(200)->assertJsonPath('id', $asset->id);
    }

    public function test_scan_lookup_returns_404_for_unknown_code(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/rental-assets/scan?code=nonexistentcode');
        $response->assertStatus(404);
    }

    public function test_cannot_delete_rented_asset(): void
    {
        $this->actingAsAdmin();
        $asset = RentalAsset::factory()->create(['status' => 'rented']);
        RentalTransaction::factory()->create(['asset_id' => $asset->id, 'status' => 'active']);

        $response = $this->deleteJson("/api/rental-assets/{$asset->id}");
        $response->assertStatus(422);
    }

    public function test_can_update_asset_status(): void
    {
        $this->actingAsAdmin();
        $asset = RentalAsset::factory()->create(['status' => 'available']);

        $response = $this->putJson("/api/rental-assets/{$asset->id}", [
            'status' => 'in_maintenance',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 'in_maintenance');
    }

    public function test_only_managers_can_create_assets(): void
    {
        $this->actingAsTechnicianDoctor();
        $facility = Facility::factory()->create();

        $response = $this->postJson('/api/rental-assets', [
            'facility_id'      => $facility->id,
            'name'             => 'Pump',
            'category'         => 'pump',
            'replacement_cost' => 1000,
            'daily_rate'       => 10,
        ]);

        $response->assertStatus(403);
    }

    public function test_show_loads_relationships(): void
    {
        $this->actingAsAdmin();
        $asset = RentalAsset::factory()->create();

        $response = $this->getJson("/api/rental-assets/{$asset->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $asset->id)
            ->assertJsonStructure(['facility', 'transactions']);
    }

    public function test_clerk_cannot_delete_rental_asset(): void
    {
        $this->actingAsInventoryClerk();
        $asset = RentalAsset::factory()->create(['status' => 'available']);

        $response = $this->deleteJson("/api/rental-assets/{$asset->id}");

        $response->assertStatus(403);
    }

    public function test_duplicate_serial_number_rejected(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        RentalAsset::factory()->create(['serial_number' => 'SN-DUPE-001']);

        $response = $this->postJson('/api/rental-assets', [
            'facility_id'      => $facility->id,
            'name'             => 'Duplicate',
            'category'         => 'pump',
            'replacement_cost' => 100,
            'daily_rate'       => 5,
            'serial_number'    => 'SN-DUPE-001',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['serial_number']);
    }

    public function test_can_upload_asset_photo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->actingAsAdmin();
        $asset = RentalAsset::factory()->create();

        $file = \Illuminate\Http\UploadedFile::fake()->image('photo.jpg', 400, 400);

        $response = $this->postJson("/api/rental-assets/{$asset->id}/photo", [
            'photo' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['photo_url']);
        $this->assertNotNull($asset->fresh()->photo_path);
    }
}
