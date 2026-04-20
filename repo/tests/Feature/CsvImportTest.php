<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_can_import_facilities_from_csv(): void
    {
        $this->actingAsAdmin();

        $csv = "external_key,name,address,city,state,zip,email\n";
        $csv .= "FAC-TEST-1,Test Hospital,123 Main St,Springfield,IL,62701,test@hospital.com\n";

        $file = UploadedFile::fake()->createWithContent('facilities.csv', $csv);

        $response = $this->postJson('/api/facilities/import', ['file' => $file]);

        $response->assertStatus(200)->assertJsonPath('status', 'completed');
        $this->assertDatabaseHas('facilities', ['external_key' => 'FAC-TEST-1', 'name' => 'Test Hospital']);
    }

    public function test_idempotent_import_updates_existing_record(): void
    {
        $this->actingAsAdmin();

        Facility::factory()->create([
            'external_key' => 'FAC-IDEM-001',
            'name'         => 'Original Name',
        ]);

        $csv = "external_key,name,address,city,state,zip\n";
        $csv .= "FAC-IDEM-001,Updated Name,456 New St,Chicago,IL,60601\n";

        $file = UploadedFile::fake()->createWithContent('facilities.csv', $csv);

        $this->postJson('/api/facilities/import', ['file' => $file]);

        $this->assertDatabaseHas('facilities', ['external_key' => 'FAC-IDEM-001', 'name' => 'Updated Name']);
        $this->assertEquals(1, Facility::where('external_key', 'FAC-IDEM-001')->count());
    }

    public function test_row_validation_errors_captured(): void
    {
        $this->actingAsAdmin();

        // Missing required 'name' field
        $csv = "external_key,name,address,city,state,zip\n";
        $csv .= "FAC-BAD-001,,123 Main,Springfield,IL,62701\n";  // empty name

        $file = UploadedFile::fake()->createWithContent('facilities.csv', $csv);

        $response = $this->postJson('/api/facilities/import', ['file' => $file]);

        $response->assertStatus(200);
        $this->assertGreaterThan(0, $response->json('error_rows'));
    }

    public function test_can_import_inventory_items(): void
    {
        $this->actingAsAdmin();

        $csv = "external_key,name,sku,category,unit_of_measure\n";
        $csv .= "ITEM-CSV-001,Test Syringe,SKU-CSV-001,surgical,unit\n";

        $file = UploadedFile::fake()->createWithContent('items.csv', $csv);

        $response = $this->postJson('/api/inventory/items/import', ['file' => $file]);

        $response->assertStatus(200)->assertJsonPath('status', 'completed');
        $this->assertDatabaseHas('inventory_items', ['external_key' => 'ITEM-CSV-001']);
    }

    public function test_export_facilities_returns_csv(): void
    {
        $this->actingAsAdmin();
        Facility::factory()->count(3)->create();

        $response = $this->getJson('/api/facilities/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('external_key', $response->streamedContent());
    }

    public function test_inventory_items_export_returns_csv_stream(): void
    {
        $this->actingAsAdmin();
        InventoryItem::factory()->create([
            'external_key'    => 'ITEM-EXP-1',
            'name'            => 'Syringe 5ml',
            'category'        => 'surgical',
            'unit_of_measure' => 'unit',
        ]);

        $response = $this->getJson('/api/inventory/items/export');

        $response->assertStatus(200);
        $body = $response->streamedContent();
        $this->assertStringContainsString('external_key', $body);
        $this->assertStringContainsString('ITEM-EXP-1', $body);
        $this->assertStringContainsString('Syringe 5ml', $body);
        $this->assertEquals('text/csv', $response->headers->get('Content-Type'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'export', 'entity_type' => 'inventory_item']);
    }

    public function test_technician_cannot_export_inventory_items(): void
    {
        $this->actingAsTechnicianDoctor();

        $this->getJson('/api/inventory/items/export')->assertStatus(403);
    }

    public function test_admin_can_import_doctors_via_csv(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create(['external_key' => 'FAC-DOC-IMP']);

        $csv  = "external_key,facility_key,first_name,last_name,specialty,email\n";
        $csv .= "DR-IMP-1,FAC-DOC-IMP,Grace,Hopper,Internal Medicine,gh@vetops.local\n";

        $file = UploadedFile::fake()->createWithContent('doctors.csv', $csv);

        $response = $this->postJson('/api/doctors/import', ['file' => $file]);

        $response->assertStatus(200)->assertJsonPath('status', 'completed');
        $this->assertDatabaseHas('doctors', [
            'external_key' => 'DR-IMP-1',
            'first_name'   => 'Grace',
            'last_name'    => 'Hopper',
            'facility_id'  => $facility->id,
        ]);
    }

    public function test_manager_cannot_import_doctors(): void
    {
        $this->actingAsManager();
        Facility::factory()->create(['external_key' => 'FAC-DOC-BLK']);

        $csv  = "external_key,facility_key,first_name,last_name\n";
        $csv .= "DR-BLK-1,FAC-DOC-BLK,Block,Attempt\n";

        $file = UploadedFile::fake()->createWithContent('doctors.csv', $csv);

        $this->postJson('/api/doctors/import', ['file' => $file])->assertStatus(403);
    }

    public function test_checksum_stored_for_imported_file(): void
    {
        $this->actingAsAdmin();

        $csv = "external_key,name,address,city,state,zip\n";
        $csv .= "FAC-CHKSUM-001,Checksum Test Hospital,789 Ave,Dallas,TX,75001\n";

        $file = UploadedFile::fake()->createWithContent('checksum.csv', $csv);

        $this->postJson('/api/facilities/import', ['file' => $file]);

        $import = \App\Models\CsvImport::first();
        $this->assertNotNull($import);
        $this->assertNotEmpty($import->file_checksum);
        $this->assertEquals(64, strlen($import->file_checksum)); // SHA-256 hex is 64 chars
    }
}
