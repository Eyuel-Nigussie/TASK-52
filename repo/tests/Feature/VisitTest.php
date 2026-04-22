<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitTest extends TestCase
{
    use RefreshDatabase;

    private function makeVisitData(?int $facilityId = null): array
    {
        $facility = $facilityId ? Facility::findOrFail($facilityId) : Facility::factory()->create();
        $patient  = Patient::factory()->create(['facility_id' => $facility->id]);
        $doctor   = Doctor::factory()->create(['facility_id' => $facility->id]);

        return [
            'facility_id' => $facility->id,
            'patient_id'  => $patient->id,
            'doctor_id'   => $doctor->id,
            'visit_date'  => now()->toDateString(),
            'status'      => 'scheduled',
        ];
    }

    public function test_can_list_visits(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        Visit::factory()->count(3)->create(['facility_id' => $tech->facility_id]);

        $response = $this->getJson('/api/visits');

        $response->assertStatus(200)
            ->assertJsonPath('total', 3);
    }

    public function test_can_create_visit(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        $data = $this->makeVisitData($tech->facility_id);

        $response = $this->postJson('/api/visits', $data);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'scheduled');
        $this->assertDatabaseHas('visits', [
            'patient_id' => $data['patient_id'],
            'doctor_id'  => $data['doctor_id'],
        ]);
    }

    public function test_can_show_visit_with_relationships(): void
    {
        $tech  = $this->actingAsTechnicianDoctor();
        $visit = Visit::factory()->create(['facility_id' => $tech->facility_id]);

        $response = $this->getJson("/api/visits/{$visit->id}");

        $response->assertStatus(200);
        $this->assertArrayHasKey('patient', $response->json());
        $this->assertArrayHasKey('doctor', $response->json());
    }

    public function test_can_update_visit_status(): void
    {
        $tech  = $this->actingAsTechnicianDoctor();
        $visit = Visit::factory()->create(['status' => 'scheduled', 'facility_id' => $tech->facility_id]);

        $response = $this->putJson("/api/visits/{$visit->id}", ['status' => 'completed']);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'completed');
    }

    public function test_can_cancel_visit(): void
    {
        $tech  = $this->actingAsTechnicianDoctor();
        $visit = Visit::factory()->create(['status' => 'scheduled', 'facility_id' => $tech->facility_id]);

        $response = $this->putJson("/api/visits/{$visit->id}", ['status' => 'cancelled']);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_invalid_status_rejected(): void
    {
        $tech  = $this->actingAsTechnicianDoctor();
        $visit = Visit::factory()->create(['facility_id' => $tech->facility_id]);

        $response = $this->putJson("/api/visits/{$visit->id}", ['status' => 'invalid_status']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_can_filter_visits_by_doctor(): void
    {
        $tech    = $this->actingAsTechnicianDoctor();
        $fid     = $tech->facility_id;
        $doctor1 = Doctor::factory()->create(['facility_id' => $fid]);
        $doctor2 = Doctor::factory()->create(['facility_id' => $fid]);
        $patient = Patient::factory()->create(['facility_id' => $fid]);

        Visit::factory()->create(['doctor_id' => $doctor1->id, 'patient_id' => $patient->id, 'facility_id' => $fid]);
        Visit::factory()->create(['doctor_id' => $doctor2->id, 'patient_id' => $patient->id, 'facility_id' => $fid]);
        Visit::factory()->create(['doctor_id' => $doctor1->id, 'patient_id' => $patient->id, 'facility_id' => $fid]);

        $response = $this->getJson("/api/visits?doctor_id={$doctor1->id}");

        $response->assertStatus(200)
            ->assertJsonPath('total', 2);
    }

    public function test_can_filter_visits_by_patient(): void
    {
        $tech     = $this->actingAsTechnicianDoctor();
        $fid      = $tech->facility_id;
        $patient1 = Patient::factory()->create(['facility_id' => $fid]);
        $patient2 = Patient::factory()->create(['facility_id' => $fid]);
        $doctor   = Doctor::factory()->create(['facility_id' => $fid]);

        Visit::factory()->create(['patient_id' => $patient1->id, 'doctor_id' => $doctor->id, 'facility_id' => $fid]);
        Visit::factory()->create(['patient_id' => $patient2->id, 'doctor_id' => $doctor->id, 'facility_id' => $fid]);

        $response = $this->getJson("/api/visits?patient_id={$patient1->id}");

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_can_filter_visits_by_date_range(): void
    {
        $tech    = $this->actingAsTechnicianDoctor();
        $fid     = $tech->facility_id;
        $patient = Patient::factory()->create(['facility_id' => $fid]);
        $doctor  = Doctor::factory()->create(['facility_id' => $fid]);

        Visit::factory()->create([
            'facility_id' => $fid,
            'patient_id'  => $patient->id,
            'doctor_id'   => $doctor->id,
            'visit_date'  => '2024-01-15',
        ]);
        Visit::factory()->create([
            'facility_id' => $fid,
            'patient_id'  => $patient->id,
            'doctor_id'   => $doctor->id,
            'visit_date'  => '2024-06-15',
        ]);

        $response = $this->getJson('/api/visits?date_from=2024-01-01&date_to=2024-03-31');

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_patient_must_exist(): void
    {
        $tech   = $this->actingAsTechnicianDoctor();
        $fid    = $tech->facility_id;
        $doctor = Doctor::factory()->create(['facility_id' => $fid]);

        $response = $this->postJson('/api/visits', [
            'facility_id' => $fid,
            'patient_id'  => 99999,
            'doctor_id'   => $doctor->id,
            'visit_date'  => now()->toDateString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['patient_id']);
    }
}
