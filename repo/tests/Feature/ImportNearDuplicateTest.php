<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CsvImport;
use App\Models\Doctor;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\Service;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ImportService must flag rows where key fields match an existing record
 * that has a different external_key — these are likely duplicates that
 * need manager review via the merge-request workflow.
 *
 * Silently creating a second record would corrupt entity resolution.
 */
class ImportNearDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private ImportService $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = app(ImportService::class);
        Storage::fake();
    }

    private function makeCsv(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_') . '.csv';
        file_put_contents($path, $content);
        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }

    public function test_doctor_import_flags_key_field_duplicate(): void
    {
        $facility = Facility::factory()->create(['external_key' => 'FAC-001']);
        Doctor::factory()->create([
            'facility_id' => $facility->id,
            'first_name'  => 'Jane',
            'last_name'   => 'Smith',
            'external_key' => 'DR-ORIGINAL',
        ]);

        $csv = "external_key,facility_key,first_name,last_name\n";
        $csv .= "DR-NEWKEY,FAC-001,Jane,Smith\n"; // same name, different external_key

        $admin = $this->actingAsAdmin();
        $file = $this->makeCsv($csv);
        $import = $this->importer->queueImport($file, 'doctor', $admin->id);
        $result = $this->importer->process($import);

        // Row must be in error list — not silently created.
        $this->assertGreaterThan(0, $result->error_rows);
        $this->assertStringContainsString('Key-field duplicate', $result->errors[0]['error']);

        // No new doctor was created.
        $this->assertCount(1, Doctor::where('facility_id', $facility->id)
            ->where('first_name', 'Jane')->where('last_name', 'Smith')->get());
    }

    public function test_patient_import_flags_key_field_duplicate(): void
    {
        $facility = Facility::factory()->create(['external_key' => 'FAC-002']);
        Patient::factory()->create([
            'facility_id'  => $facility->id,
            'name'         => 'Buddy',
            'species'      => 'dog',
            'external_key' => 'PAT-ORIGINAL',
        ]);

        $csv = "external_key,facility_key,name,species\n";
        $csv .= "PAT-NEWKEY,FAC-002,Buddy,dog\n";

        $admin = $this->actingAsAdmin();
        $file = $this->makeCsv($csv);
        $import = $this->importer->queueImport($file, 'patient', $admin->id);
        $result = $this->importer->process($import);

        $this->assertGreaterThan(0, $result->error_rows);
        $this->assertStringContainsString('Key-field duplicate', $result->errors[0]['error']);

        $this->assertCount(1, Patient::where('facility_id', $facility->id)
            ->where('name', 'Buddy')->where('species', 'dog')->get());
    }

    public function test_service_import_flags_key_field_duplicate(): void
    {
        Service::factory()->create([
            'name'         => 'Dental Clean',
            'category'     => 'dental',
            'external_key' => 'SVC-ORIGINAL',
        ]);

        $csv = "external_key,name,category,duration_minutes\n";
        $csv .= "SVC-NEWKEY,Dental Clean,dental,30\n";

        $admin = $this->actingAsAdmin();
        $file = $this->makeCsv($csv);
        $import = $this->importer->queueImport($file, 'service', $admin->id);
        $result = $this->importer->process($import);

        $this->assertGreaterThan(0, $result->error_rows);
        $this->assertStringContainsString('Key-field duplicate', $result->errors[0]['error']);

        $this->assertCount(1, Service::where('name', 'Dental Clean')->where('category', 'dental')->get());
    }

    public function test_doctor_import_succeeds_when_external_key_matches_existing(): void
    {
        $facility = Facility::factory()->create(['external_key' => 'FAC-003']);
        Doctor::factory()->create([
            'facility_id' => $facility->id,
            'first_name'  => 'Bob',
            'last_name'   => 'Jones',
            'external_key' => 'DR-BOB',
            'specialty'   => 'old_specialty',
        ]);

        $csv = "external_key,facility_key,first_name,last_name,specialty\n";
        $csv .= "DR-BOB,FAC-003,Bob,Jones,neurology\n"; // same external_key = update

        $admin = $this->actingAsAdmin();
        $file = $this->makeCsv($csv);
        $import = $this->importer->queueImport($file, 'doctor', $admin->id);
        $result = $this->importer->process($import);

        $this->assertEquals(0, $result->error_rows);
        $this->assertEquals('neurology', Doctor::where('external_key', 'DR-BOB')->first()->specialty);
    }

    public function test_doctor_import_succeeds_when_different_name_at_same_facility(): void
    {
        $facility = Facility::factory()->create(['external_key' => 'FAC-004']);

        $csv = "external_key,facility_key,first_name,last_name\n";
        $csv .= "DR-ALICE,FAC-004,Alice,Walker\n";

        $admin = $this->actingAsAdmin();
        $file = $this->makeCsv($csv);
        $import = $this->importer->queueImport($file, 'doctor', $admin->id);
        $result = $this->importer->process($import);

        $this->assertEquals(0, $result->error_rows);
        $this->assertDatabaseHas('doctors', ['external_key' => 'DR-ALICE', 'first_name' => 'Alice']);
    }
}
