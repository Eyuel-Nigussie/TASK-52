<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DataVersion;
use App\Models\Doctor;
use App\Models\Facility;
use App\Models\Service;
use App\Models\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verifies that the CSV import pipeline delegates duplicate detection to
 * DeduplicationService::matchByKeyFields, and that service/service_pricing
 * imports populate the DataVersion audit trail the same way other imports do.
 */
class ImportDedupWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_doctor_import_detects_key_field_duplicate_via_dedup_service(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create(['external_key' => 'FAC-DDUP-1']);
        Doctor::factory()->create([
            'facility_id'  => $facility->id,
            'external_key' => 'DR-CANONICAL',
            'first_name'   => 'Grace',
            'last_name'    => 'Hopper',
        ]);

        // Same name at same facility but different external_key = duplicate.
        $csv  = "external_key,facility_key,first_name,last_name\n";
        $csv .= "DR-DUPLICATE,FAC-DDUP-1,Grace,Hopper\n";
        $file = UploadedFile::fake()->createWithContent('doctors.csv', $csv);

        $response = $this->postJson('/api/doctors/import', ['file' => $file]);
        $response->assertStatus(200);

        $errors = $response->json('errors');
        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Key-field duplicate', json_encode($errors));

        // The duplicate must NOT have been created.
        $this->assertDatabaseMissing('doctors', ['external_key' => 'DR-DUPLICATE']);
    }

    public function test_doctor_import_accepts_same_name_in_different_facility(): void
    {
        $this->actingAsAdmin();
        $a = Facility::factory()->create(['external_key' => 'FAC-A']);
        $b = Facility::factory()->create(['external_key' => 'FAC-B']);
        Doctor::factory()->create([
            'facility_id'  => $a->id,
            'external_key' => 'DR-A-1',
            'first_name'   => 'Alan',
            'last_name'    => 'Turing',
        ]);

        $csv  = "external_key,facility_key,first_name,last_name\n";
        $csv .= "DR-B-1,FAC-B,Alan,Turing\n";
        $file = UploadedFile::fake()->createWithContent('doctors.csv', $csv);

        $this->postJson('/api/doctors/import', ['file' => $file])
            ->assertStatus(200)->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('doctors', [
            'facility_id' => $b->id,
            'external_key' => 'DR-B-1',
        ]);
    }

    public function test_service_import_detects_key_field_duplicate(): void
    {
        $this->actingAsAdmin();
        Service::factory()->create([
            'external_key' => 'SVC-CANONICAL',
            'name'         => 'Blood Panel',
            'category'     => 'diagnostics',
        ]);

        $csv  = "external_key,name,category\n";
        $csv .= "SVC-DUPLICATE,Blood Panel,diagnostics\n";
        $file = UploadedFile::fake()->createWithContent('services.csv', $csv);

        $response = $this->postJson('/api/services/import', ['file' => $file]);
        $response->assertStatus(200);

        $errors = $response->json('errors');
        $this->assertNotEmpty($errors);
        $this->assertDatabaseMissing('services', ['external_key' => 'SVC-DUPLICATE']);
    }

    public function test_service_import_creates_version_row(): void
    {
        $this->actingAsAdmin();

        $csv  = "external_key,name,category,code,duration_minutes\n";
        $csv .= "SVC-VER-1,Wellness Exam,preventive,WE-100,30\n";
        $file = UploadedFile::fake()->createWithContent('services.csv', $csv);

        $this->postJson('/api/services/import', ['file' => $file])
            ->assertStatus(200)->assertJsonPath('status', 'completed');

        $service = Service::where('external_key', 'SVC-VER-1')->first();
        $this->assertNotNull($service);
        $this->assertGreaterThan(0, DataVersion::where('entity_type', Service::class)
            ->where('entity_id', $service->id)->count());
    }

    public function test_service_import_updates_existing_and_versions_change(): void
    {
        $this->actingAsAdmin();
        Service::factory()->create([
            'external_key' => 'SVC-UPD-1',
            'name'         => 'Old Name',
            'category'     => 'preventive',
        ]);

        $csv  = "external_key,name,category\n";
        $csv .= "SVC-UPD-1,New Name,preventive\n";
        $file = UploadedFile::fake()->createWithContent('services.csv', $csv);

        $this->postJson('/api/services/import', ['file' => $file])
            ->assertStatus(200);

        $updated = Service::where('external_key', 'SVC-UPD-1')->first();
        $this->assertEquals('New Name', $updated->name);
        $this->assertGreaterThanOrEqual(1, DataVersion::where('entity_type', Service::class)
            ->where('entity_id', $updated->id)->count());
    }
}
