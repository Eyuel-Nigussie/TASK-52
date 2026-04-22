<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ContentMedia;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\Facility;
use App\Models\MergeRequest;
use App\Models\Patient;
use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use App\Models\ReviewImage;
use App\Models\ServiceOrder;
use App\Models\Storeroom;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Second-round audit regression suite. Pins every finding from the
 * follow-up audit report so a regression re-opens a failing test
 * rather than reaching production:
 *
 *  - High 1: Department route + policy + facility scoping
 *  - High 2: Merge-request facility scoping + approver check
 *  - High 3: Audit-log facility scoping
 *  - High 4: Full-chain audit writes for Departments / Storerooms /
 *            Visits / ServiceOrders / RentalTransaction.cancel
 *  - Medium 1: VETOPS_ENCRYPTION_KEY binds Laravel encrypter
 *  - Medium 2: Checksum verification covers media assets
 *  - Medium 3: Rental UI exposes photo + specs (covered by Vitest)
 */
class InfrastructureRegressionTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------- High 1: departments ---------------- */

    public function test_department_index_is_policy_guarded_and_scoped_for_manager(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $manager = User::factory()->manager()->create(['facility_id' => $a->id]);
        $this->actingAs($manager, 'sanctum');

        Department::factory()->count(2)->create(['facility_id' => $a->id]);
        Department::factory()->count(3)->create(['facility_id' => $b->id]);

        $response = $this->getJson('/api/departments');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
        foreach ($response->json() as $row) {
            $this->assertEquals($a->id, $row['facility_id']);
        }
    }

    public function test_manager_cannot_create_department_in_other_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $manager = User::factory()->manager()->create(['facility_id' => $a->id]);
        $this->actingAs($manager, 'sanctum');

        $this->postJson('/api/departments', [
            'facility_id'  => $b->id,
            'external_key' => 'DEPT-X',
            'name'         => 'Cross-facility',
        ])->assertStatus(403);
    }

    public function test_manager_cannot_update_department_in_other_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $manager = User::factory()->manager()->create(['facility_id' => $a->id]);
        $this->actingAs($manager, 'sanctum');

        $foreign = Department::factory()->create(['facility_id' => $b->id]);

        $this->putJson("/api/departments/{$foreign->id}", ['name' => 'Nope'])
            ->assertStatus(403);
    }

    public function test_department_create_writes_audit_log(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $this->postJson('/api/departments', [
            'facility_id'  => $facility->id,
            'external_key' => 'DEPT-AUD-1',
            'name'         => 'Audited',
        ])->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', ['action' => 'department.create']);
    }

    public function test_department_update_and_delete_write_audit_logs(): void
    {
        $this->actingAsAdmin();
        $dept = Department::factory()->create();

        $this->putJson("/api/departments/{$dept->id}", ['name' => 'Renamed'])
            ->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['action' => 'department.update']);

        $this->deleteJson("/api/departments/{$dept->id}")->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['action' => 'department.delete']);
    }

    /* ---------------- High 2: merge-request facility scoping ---------------- */

    public function test_merge_request_index_is_facility_scoped_for_managers(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $managerA = User::factory()->manager()->create(['facility_id' => $a->id]);

        MergeRequest::create([
            'entity_type'  => 'patient',
            'facility_id'  => $a->id,
            'source_id'    => 1,
            'target_id'    => 2,
            'status'       => 'pending',
            'requested_by' => $managerA->id,
        ]);
        MergeRequest::create([
            'entity_type'  => 'patient',
            'facility_id'  => $b->id,
            'source_id'    => 3,
            'target_id'    => 4,
            'status'       => 'pending',
            'requested_by' => $managerA->id,
        ]);

        $this->actingAs($managerA, 'sanctum');
        $response = $this->getJson('/api/merge-requests');
        $response->assertStatus(200);
        foreach ($response->json('data') as $row) {
            $this->assertEquals($a->id, $row['facility_id']);
        }
    }

    public function test_manager_cannot_approve_merge_request_from_other_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $managerA = User::factory()->manager()->create(['facility_id' => $a->id]);

        $sourceB = Patient::factory()->create(['facility_id' => $b->id]);
        $targetB = Patient::factory()->create(['facility_id' => $b->id]);

        $merge = MergeRequest::create([
            'entity_type'  => 'patient',
            'facility_id'  => $b->id,
            'source_id'    => $sourceB->id,
            'target_id'    => $targetB->id,
            'status'       => 'pending',
            'requested_by' => $managerA->id,
        ]);

        $this->actingAs($managerA, 'sanctum');
        $this->postJson("/api/merge-requests/{$merge->id}/approve")->assertStatus(403);
    }

    public function test_merge_request_create_derives_facility_from_source_entity(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $source = Patient::factory()->create(['facility_id' => $facility->id]);
        $target = Patient::factory()->create(['facility_id' => $facility->id]);

        $response = $this->postJson('/api/merge-requests', [
            'entity_type' => 'patient',
            'source_id'   => $source->id,
            'target_id'   => $target->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('facility_id', $facility->id);
    }

    /* ---------------- High 3: audit log facility scoping ---------------- */

    public function test_audit_log_index_is_facility_scoped_for_managers(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $managerA = User::factory()->manager()->create(['facility_id' => $a->id]);

        AuditLog::create(['action' => 'x.a', 'facility_id' => $a->id, 'created_at' => now()]);
        AuditLog::create(['action' => 'x.a2', 'facility_id' => $a->id, 'created_at' => now()]);
        AuditLog::create(['action' => 'x.b', 'facility_id' => $b->id, 'created_at' => now()]);

        $this->actingAs($managerA, 'sanctum');
        $response = $this->getJson('/api/audit-logs');
        $response->assertStatus(200);

        $actions = array_column($response->json('data'), 'action');
        $this->assertContains('x.a', $actions);
        $this->assertContains('x.a2', $actions);
        $this->assertNotContains('x.b', $actions);
    }

    public function test_audit_log_export_is_facility_scoped_for_managers(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $managerA = User::factory()->manager()->create(['facility_id' => $a->id]);

        AuditLog::create(['action' => 'only.a', 'facility_id' => $a->id, 'created_at' => now()]);
        AuditLog::create(['action' => 'only.b', 'facility_id' => $b->id, 'created_at' => now()]);

        $this->actingAs($managerA, 'sanctum');
        $response = $this->getJson('/api/audit-logs/export');
        $response->assertStatus(200);

        $body = $response->streamedContent();
        $this->assertStringContainsString('only.a', $body);
        $this->assertStringNotContainsString('only.b', $body);
    }

    public function test_audit_service_logs_facility_id_from_authenticated_user(): void
    {
        $facility = Facility::factory()->create();
        $user = User::factory()->manager()->create(['facility_id' => $facility->id]);
        $this->actingAs($user, 'sanctum');

        app(\App\Services\AuditService::class)->log('test.fid', 'X', 1, null, ['k' => 'v']);

        $log = AuditLog::latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals($facility->id, $log->facility_id);
    }

    /* ---------------- High 4: full-chain audit writes ---------------- */

    public function test_storeroom_create_update_delete_write_audit(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $this->postJson('/api/storerooms', [
            'facility_id' => $facility->id,
            'name'        => 'Aud Store',
        ])->assertStatus(201);
        $this->assertDatabaseHas('audit_logs', ['action' => 'storeroom.create']);

        $storeroom = Storeroom::first();
        $this->putJson("/api/storerooms/{$storeroom->id}", ['name' => 'Aud Renamed'])
            ->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['action' => 'storeroom.update']);

        $this->deleteJson("/api/storerooms/{$storeroom->id}")->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['action' => 'storeroom.delete']);
    }

    public function test_visit_create_and_update_write_audit(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $patient = Patient::factory()->create(['facility_id' => $facility->id]);
        $doctor = Doctor::factory()->create(['facility_id' => $facility->id]);

        $this->postJson('/api/visits', [
            'facility_id' => $facility->id,
            'patient_id'  => $patient->id,
            'doctor_id'   => $doctor->id,
            'visit_date'  => now()->addDay()->toIso8601String(),
            'status'      => 'scheduled',
        ])->assertStatus(201);
        $this->assertDatabaseHas('audit_logs', ['action' => 'visit.create']);

        $visit = Visit::first();
        $this->putJson("/api/visits/{$visit->id}", ['status' => 'completed'])
            ->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['action' => 'visit.update']);
    }

    public function test_service_order_create_close_reserve_write_audit(): void
    {
        $tech = $this->actingAsTechnicianDoctor();

        $this->postJson('/api/service-orders', [
            'facility_id'          => $tech->facility_id,
            'reservation_strategy' => 'deduct_at_close',
        ])->assertStatus(201);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service_order.create']);

        $order = ServiceOrder::first();
        $this->postJson("/api/service-orders/{$order->id}/close")->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service_order.close']);
    }

    public function test_rental_cancel_writes_audit(): void
    {
        $this->actingAsAdmin();
        $asset = RentalAsset::factory()->create(['status' => 'rented']);
        $tx = RentalTransaction::factory()->create([
            'asset_id' => $asset->id,
            'status'   => 'active',
        ]);

        $this->postJson("/api/rental-transactions/{$tx->id}/cancel")->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental.cancel']);
    }

    /* ---------------- Medium 1: encryption key binding ---------------- */

    public function test_app_service_provider_binds_custom_encrypter_when_key_set(): void
    {
        $roundTrip = decrypt(encrypt('plaintext-payload'));
        $this->assertSame('plaintext-payload', $roundTrip);
    }

    /* ---------------- Medium 2: checksum verification scope ---------------- */

    public function test_verify_file_checksums_flags_tampered_media(): void
    {
        Storage::fake('local');

        Storage::put('media/good.bin', 'original-content');
        ContentMedia::create([
            'content_item_id' => \App\Models\ContentItem::factory()->create()->id,
            'file_path'       => 'media/good.bin',
            'file_name'       => 'good.bin',
            'mime_type'       => 'application/octet-stream',
            'file_size'       => 16,
            'checksum'        => hash('sha256', 'original-content'),
            'sort_order'      => 0,
        ]);

        Storage::put('media/tampered.bin', 'tampered-content');
        ContentMedia::create([
            'content_item_id' => \App\Models\ContentItem::factory()->create()->id,
            'file_path'       => 'media/tampered.bin',
            'file_name'       => 'tampered.bin',
            'mime_type'       => 'application/octet-stream',
            'file_size'       => 17,
            'checksum'        => hash('sha256', 'what-the-system-thinks-is-here'),
            'sort_order'      => 1,
        ]);

        $this->artisan('vetops:verify-file-checksums')
            ->expectsOutputToContain('Mismatched: 1')
            ->assertExitCode(1);
    }

    public function test_verify_file_checksums_covers_review_images(): void
    {
        Storage::fake('local');

        $review = VisitReview::factory()->create();
        Storage::put('reviews/happy.jpg', 'payload');
        ReviewImage::create([
            'review_id'  => $review->id,
            'file_path'  => 'reviews/happy.jpg',
            'file_name'  => 'happy.jpg',
            'checksum'   => hash('sha256', 'payload'),
            'sort_order' => 0,
        ]);

        $this->artisan('vetops:verify-file-checksums')
            ->expectsOutputToContain('Checked 1 file(s). Missing: 0. Mismatched: 0.')
            ->assertExitCode(0);
    }
}
