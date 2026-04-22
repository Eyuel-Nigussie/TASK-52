<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Facility;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorPatientTest extends TestCase
{
    use RefreshDatabase;

    // ─── Doctor Tests ─────────────────────────────────────────────────────────

    public function test_can_list_doctors(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        Doctor::factory()->count(3)->create(['facility_id' => $tech->facility_id]);

        $response = $this->getJson('/api/doctors');

        $response->assertStatus(200)
            ->assertJsonPath('total', 3);
    }

    public function test_admin_can_create_doctor(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $response = $this->postJson('/api/doctors', [
            'facility_id'    => $facility->id,
            'external_key'   => 'DR-TEST-001',
            'first_name'     => 'Jane',
            'last_name'      => 'Smith',
            'specialty'      => 'Surgery',
            'license_number' => 'LIC-9999999',
            'email'          => 'jane.smith@vetclinic.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('last_name', 'Smith');
        $this->assertDatabaseHas('doctors', ['license_number' => 'LIC-9999999']);
    }

    public function test_manager_can_create_doctor(): void
    {
        $manager = $this->actingAsManager();

        $response = $this->postJson('/api/doctors', [
            'facility_id'  => $manager->facility_id,
            'external_key' => 'DR-MGR-001',
            'first_name'   => 'Bob',
            'last_name'    => 'Jones',
        ]);

        $response->assertStatus(201);
    }

    public function test_clerk_cannot_create_doctor(): void
    {
        $clerk = $this->actingAsInventoryClerk();

        $response = $this->postJson('/api/doctors', [
            'facility_id'  => $clerk->facility_id,
            'external_key' => 'DR-CLK-001',
            'first_name'   => 'Alice',
            'last_name'    => 'Brown',
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_sees_masked_doctor_phone(): void
    {
        $clerk = $this->actingAsInventoryClerk();
        $doctor = Doctor::factory()->create([
            'facility_id'     => $clerk->facility_id,
            'phone_encrypted' => encrypt('(555) 777-8888'),
        ]);

        $response = $this->getJson("/api/doctors/{$doctor->id}");

        $response->assertStatus(200);
        $this->assertStringContainsString('*', $response->json('phone'));
    }

    public function test_admin_sees_full_doctor_phone(): void
    {
        $this->actingAsAdmin();
        $doctor = Doctor::factory()->create(['phone_encrypted' => encrypt('(555) 777-8888')]);

        $response = $this->getJson("/api/doctors/{$doctor->id}");

        $response->assertStatus(200)
            ->assertJsonPath('phone', '(555) 777-8888');
    }

    public function test_admin_can_update_doctor(): void
    {
        $this->actingAsAdmin();
        $doctor = Doctor::factory()->create();

        $response = $this->putJson("/api/doctors/{$doctor->id}", [
            'specialty' => 'Neurology',
            'active'    => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('specialty', 'Neurology')
            ->assertJsonPath('active', false);
    }

    public function test_admin_can_delete_doctor(): void
    {
        $this->actingAsAdmin();
        $doctor = Doctor::factory()->create();

        $response = $this->deleteJson("/api/doctors/{$doctor->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('doctors', ['id' => $doctor->id]);
    }

    public function test_manager_cannot_delete_doctor(): void
    {
        $manager = $this->actingAsManager();
        $doctor = Doctor::factory()->create(['facility_id' => $manager->facility_id]);

        $response = $this->deleteJson("/api/doctors/{$doctor->id}");

        $response->assertStatus(403);
    }

    public function test_duplicate_license_number_rejected(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        Doctor::factory()->create(['license_number' => 'LIC-DUPE-001']);

        $response = $this->postJson('/api/doctors', [
            'facility_id'    => $facility->id,
            'external_key'   => 'DR-NEW-001',
            'first_name'     => 'Dup',
            'last_name'      => 'Lic',
            'license_number' => 'LIC-DUPE-001',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['license_number']);
    }

    public function test_can_filter_doctors_by_facility(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        // Both facilities are different from the tech's facility; ScopesByFacility
        // pins the tech to their own facility, so we create the expected doctors there.
        Doctor::factory()->count(2)->create(['facility_id' => $tech->facility_id]);
        // Doctors in an unrelated facility should not appear in the tech's results.
        Doctor::factory()->count(1)->create();

        $response = $this->getJson("/api/doctors?facility_id={$tech->facility_id}");

        $response->assertStatus(200)
            ->assertJsonPath('total', 2);
    }

    // ─── Patient Tests ────────────────────────────────────────────────────────

    public function test_can_list_patients(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        Patient::factory()->count(4)->create(['facility_id' => $tech->facility_id]);

        $response = $this->getJson('/api/patients');

        $response->assertStatus(200)
            ->assertJsonPath('total', 4);
    }

    public function test_can_create_patient(): void
    {
        $tech = $this->actingAsTechnicianDoctor();

        $response = $this->postJson('/api/patients', [
            'facility_id'  => $tech->facility_id,
            'external_key' => 'PAT-TEST-001',
            'name'         => 'Buddy',
            'species'      => 'canine',
            'breed'        => 'Labrador',
            'owner_name'   => 'John Doe',
            'owner_email'  => 'john@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Buddy');
        $this->assertDatabaseHas('patients', ['external_key' => 'PAT-TEST-001']);
    }

    public function test_patient_owner_phone_masked_for_non_admin(): void
    {
        $clerk = $this->actingAsInventoryClerk();
        $patient = Patient::factory()->create([
            'facility_id'           => $clerk->facility_id,
            'owner_phone_encrypted' => encrypt('(555) 111-2222'),
        ]);

        $response = $this->getJson("/api/patients/{$patient->id}");

        $response->assertStatus(200);
        $this->assertStringContainsString('*', $response->json('owner_phone'));
    }

    public function test_admin_sees_full_patient_owner_phone(): void
    {
        $this->actingAsAdmin();
        $patient = Patient::factory()->create(['owner_phone_encrypted' => encrypt('(555) 111-2222')]);

        $response = $this->getJson("/api/patients/{$patient->id}");

        $response->assertStatus(200)
            ->assertJsonPath('owner_phone', '(555) 111-2222');
    }

    public function test_can_update_patient(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        $patient = Patient::factory()->create(['facility_id' => $tech->facility_id]);

        $response = $this->putJson("/api/patients/{$patient->id}", [
            'species' => 'feline',
            'active'  => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('species', 'feline');
    }

    public function test_admin_can_delete_patient(): void
    {
        $this->actingAsAdmin();
        $patient = Patient::factory()->create();

        $response = $this->deleteJson("/api/patients/{$patient->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('patients', ['id' => $patient->id]);
    }

    public function test_clerk_cannot_delete_patient(): void
    {
        $clerk = $this->actingAsInventoryClerk();
        $patient = Patient::factory()->create(['facility_id' => $clerk->facility_id]);

        $response = $this->deleteJson("/api/patients/{$patient->id}");

        $response->assertStatus(403);
    }

    public function test_can_search_patients_by_name(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        // Pin both name and owner_name so faker-generated strings can't
        // accidentally match the search term.
        Patient::factory()->create([
            'facility_id' => $tech->facility_id,
            'name'        => 'Uniquepetname',
            'owner_name'  => 'Nomatch Jones',
        ]);
        Patient::factory()->create([
            'facility_id' => $tech->facility_id,
            'name'        => 'Whiskers',
            'owner_name'  => 'Other Person',
        ]);

        $response = $this->getJson('/api/patients?search=Uniquepetname');

        $response->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Uniquepetname');
    }

    public function test_can_search_patients_by_owner_name(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        Patient::factory()->create([
            'facility_id' => $tech->facility_id,
            'name'        => 'Petzero',
            'owner_name'  => 'Zzunique Owner',
        ]);
        Patient::factory()->create([
            'facility_id' => $tech->facility_id,
            'name'        => 'Petone',
            'owner_name'  => 'Other Person',
        ]);

        $response = $this->getJson('/api/patients?search=Zzunique');

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }
}
