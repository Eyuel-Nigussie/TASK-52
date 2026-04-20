<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DataVersion;
use App\Models\Doctor;
use App\Models\Facility;
use App\Models\MergeRequest;
use App\Models\Patient;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The prompt requires approve-merge to actually resolve the duplicate:
 * relink foreign keys, soft-delete the source, preserve provenance.
 * Status-only transitions are not acceptable.
 */
class MergeExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_approving_patient_merge_relinks_visits_and_soft_deletes_source(): void
    {
        $admin = $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $source = Patient::factory()->create(['facility_id' => $facility->id, 'name' => 'Rex Duplicate']);
        $target = Patient::factory()->create(['facility_id' => $facility->id, 'name' => 'Rex Canonical']);

        $doctor = Doctor::factory()->create(['facility_id' => $facility->id]);
        $visit1 = Visit::factory()->create(['facility_id' => $facility->id, 'patient_id' => $source->id, 'doctor_id' => $doctor->id]);
        $visit2 = Visit::factory()->create(['facility_id' => $facility->id, 'patient_id' => $source->id, 'doctor_id' => $doctor->id]);
        $otherVisit = Visit::factory()->create(['facility_id' => $facility->id, 'patient_id' => $target->id, 'doctor_id' => $doctor->id]);

        $merge = MergeRequest::create([
            'entity_type'  => 'patient',
            'source_id'    => $source->id,
            'target_id'    => $target->id,
            'status'       => 'pending',
            'requested_by' => $admin->id,
        ]);

        $this->postJson("/api/merge-requests/{$merge->id}/approve")->assertStatus(200);

        // Both of source's visits must now point at target; the third visit is unchanged.
        $this->assertEquals($target->id, Visit::find($visit1->id)->patient_id);
        $this->assertEquals($target->id, Visit::find($visit2->id)->patient_id);
        $this->assertEquals($target->id, Visit::find($otherVisit->id)->patient_id);

        // Source is soft-deleted.
        $this->assertSoftDeleted('patients', ['id' => $source->id]);

        // Target remains visible.
        $this->assertDatabaseHas('patients', ['id' => $target->id, 'deleted_at' => null]);
    }

    public function test_approving_doctor_merge_relinks_visits_and_reviews(): void
    {
        $admin = $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $patient = Patient::factory()->create(['facility_id' => $facility->id]);
        $source = Doctor::factory()->create(['facility_id' => $facility->id]);
        $target = Doctor::factory()->create(['facility_id' => $facility->id]);

        $visit = Visit::factory()->create([
            'facility_id' => $facility->id,
            'patient_id'  => $patient->id,
            'doctor_id'   => $source->id,
            'status'      => 'completed',
        ]);
        $review = VisitReview::factory()->create([
            'visit_id'    => $visit->id,
            'facility_id' => $facility->id,
            'doctor_id'   => $source->id,
        ]);

        $merge = MergeRequest::create([
            'entity_type'  => 'doctor',
            'source_id'    => $source->id,
            'target_id'    => $target->id,
            'status'       => 'pending',
            'requested_by' => $admin->id,
        ]);

        $this->postJson("/api/merge-requests/{$merge->id}/approve")->assertStatus(200);

        $this->assertEquals($target->id, Visit::find($visit->id)->doctor_id);
        $this->assertEquals($target->id, VisitReview::find($review->id)->doctor_id);
        $this->assertSoftDeleted('doctors', ['id' => $source->id]);
    }

    public function test_merge_execution_records_audit_with_relinked_counts(): void
    {
        $admin = $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $source = Patient::factory()->create(['facility_id' => $facility->id]);
        $target = Patient::factory()->create(['facility_id' => $facility->id]);
        $doctor = Doctor::factory()->create(['facility_id' => $facility->id]);
        Visit::factory()->count(2)->create([
            'facility_id' => $facility->id,
            'patient_id'  => $source->id,
            'doctor_id'   => $doctor->id,
        ]);

        $merge = MergeRequest::create([
            'entity_type'  => 'patient',
            'source_id'    => $source->id,
            'target_id'    => $target->id,
            'status'       => 'pending',
            'requested_by' => $admin->id,
        ]);

        $this->postJson("/api/merge-requests/{$merge->id}/approve")->assertStatus(200);

        $mergeAudit = AuditLog::where('action', 'entity.merge')->first();
        $this->assertNotNull($mergeAudit);
        $this->assertEquals($source->id, $mergeAudit->new_values['source_id']);
        $this->assertEquals($target->id, $mergeAudit->new_values['target_id']);
        $this->assertGreaterThanOrEqual(2, $mergeAudit->new_values['relinked_counts'][Visit::class]);
    }

    public function test_merge_execution_records_pre_merge_snapshots_for_reversal(): void
    {
        $admin = $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $source = Patient::factory()->create(['facility_id' => $facility->id, 'name' => 'Original Source']);
        $target = Patient::factory()->create(['facility_id' => $facility->id, 'name' => 'Original Target']);

        $merge = MergeRequest::create([
            'entity_type'  => 'patient',
            'source_id'    => $source->id,
            'target_id'    => $target->id,
            'status'       => 'pending',
            'requested_by' => $admin->id,
        ]);

        $this->postJson("/api/merge-requests/{$merge->id}/approve")->assertStatus(200);

        // Both source and target got pre-merge snapshots.
        $this->assertGreaterThan(0, DataVersion::where('entity_type', Patient::class)->where('entity_id', $source->id)->count());
        $this->assertGreaterThan(0, DataVersion::where('entity_type', Patient::class)->where('entity_id', $target->id)->count());
    }

    public function test_cross_facility_merge_is_rejected(): void
    {
        $admin = $this->actingAsAdmin();
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $source = Patient::factory()->create(['facility_id' => $a->id]);
        $target = Patient::factory()->create(['facility_id' => $b->id]);

        $merge = MergeRequest::create([
            'entity_type'  => 'patient',
            'source_id'    => $source->id,
            'target_id'    => $target->id,
            'status'       => 'pending',
            'requested_by' => $admin->id,
        ]);

        $this->postJson("/api/merge-requests/{$merge->id}/approve")
            ->assertStatus(422);

        // Nothing was merged.
        $this->assertDatabaseHas('patients', ['id' => $source->id, 'deleted_at' => null]);
    }

    public function test_unsupported_entity_type_is_rejected_at_creation(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/merge-requests', [
            'entity_type' => 'facility',
            'source_id'   => 1,
            'target_id'   => 2,
        ])->assertStatus(422)->assertJsonValidationErrors(['entity_type']);
    }

    public function test_rejection_does_not_relink_or_delete(): void
    {
        $admin = $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $source = Patient::factory()->create(['facility_id' => $facility->id]);
        $target = Patient::factory()->create(['facility_id' => $facility->id]);
        $doctor = Doctor::factory()->create(['facility_id' => $facility->id]);
        $visit = Visit::factory()->create([
            'facility_id' => $facility->id,
            'patient_id'  => $source->id,
            'doctor_id'   => $doctor->id,
        ]);

        $merge = MergeRequest::create([
            'entity_type'  => 'patient',
            'source_id'    => $source->id,
            'target_id'    => $target->id,
            'status'       => 'pending',
            'requested_by' => $admin->id,
        ]);

        $this->postJson("/api/merge-requests/{$merge->id}/reject")->assertStatus(200);

        $this->assertEquals($source->id, Visit::find($visit->id)->patient_id);
        $this->assertDatabaseHas('patients', ['id' => $source->id, 'deleted_at' => null]);
    }
}
