<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ContentItem;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\Facility;
use App\Models\MergeRequest;
use App\Models\Patient;
use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use App\Models\Storeroom;
use App\Models\User;
use App\Models\VisitReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression suite: null-facility non-admin accounts must be denied
 * everywhere. All policy sharesFacility()/view() fallbacks return false;
 * all controller list scopes apply whereRaw('1 = 0'); all create/export/
 * dashboard endpoints abort(403).
 */
class NullFacilityDenyTest extends TestCase
{
    use RefreshDatabase;

    private function nullFacilityManager(): User
    {
        $user = User::factory()->manager()->create(['facility_id' => null]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    private function nullFacilityClerk(): User
    {
        $user = User::factory()->inventoryClerk()->create(['facility_id' => null]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    private function nullFacilityEditor(): User
    {
        $user = User::factory()->contentEditor()->create(['facility_id' => null]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    /* ---- audit-log list returns empty set ---- */

    public function test_null_facility_manager_audit_log_list_is_empty(): void
    {
        $facility = Facility::factory()->create();
        AuditLog::create(['action' => 'x.test', 'facility_id' => $facility->id, 'created_at' => now()]);

        $this->nullFacilityManager();

        $this->getJson('/api/audit-logs')->assertStatus(200)->assertJsonPath('data', []);
    }

    /* ---- audit-log export returns empty CSV ---- */

    public function test_null_facility_manager_audit_log_export_is_empty(): void
    {
        $facility = Facility::factory()->create();
        AuditLog::create(['action' => 'export.test', 'facility_id' => $facility->id, 'created_at' => now()]);

        $this->nullFacilityManager();

        $response = $this->getJson('/api/audit-logs/export');
        $response->assertStatus(200);
        $this->assertStringNotContainsString('export.test', $response->streamedContent());
    }

    /* ---- merge-request list returns empty set ---- */

    public function test_null_facility_manager_merge_request_list_is_empty(): void
    {
        $facility = Facility::factory()->create();
        $requester = User::factory()->manager()->create(['facility_id' => $facility->id]);
        MergeRequest::create([
            'entity_type'  => 'patient',
            'facility_id'  => $facility->id,
            'source_id'    => 1,
            'target_id'    => 2,
            'status'       => 'pending',
            'requested_by' => $requester->id,
        ]);

        $this->nullFacilityManager();

        $this->getJson('/api/merge-requests')->assertStatus(200)->assertJsonPath('data', []);
    }

    /* ---- dedup candidates returns 403 ---- */

    public function test_null_facility_manager_dedup_candidates_is_denied(): void
    {
        $this->nullFacilityManager();

        $this->getJson('/api/dedup/candidates?entity_type=doctor')->assertStatus(403);
    }

    /* ---- facility show denied by policy ---- */

    public function test_null_facility_non_admin_cannot_view_facility_object(): void
    {
        $facility = Facility::factory()->create();
        $this->nullFacilityManager();

        $this->getJson("/api/facilities/{$facility->id}")->assertStatus(403);
    }

    /* ---- facility export denied ---- */

    public function test_null_facility_manager_facility_export_is_denied(): void
    {
        Facility::factory()->create();
        $this->nullFacilityManager();

        $this->getJson('/api/facilities/export')->assertStatus(403);
    }

    /* ---- storeroom create denied ---- */

    public function test_null_facility_manager_cannot_create_storeroom(): void
    {
        $facility = Facility::factory()->create();
        $this->nullFacilityManager();

        $this->postJson('/api/storerooms', ['facility_id' => $facility->id, 'name' => 'S1'])
            ->assertStatus(403);
    }

    /* ---- storeroom update denied by policy (view is a pre-condition) ---- */

    public function test_null_facility_non_admin_cannot_update_storeroom(): void
    {
        $facility = Facility::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $facility->id]);
        $this->nullFacilityManager();

        $this->putJson("/api/storerooms/{$storeroom->id}", ['name' => 'Should Fail'])
            ->assertStatus(403);
    }

    /* ---- department create denied ---- */

    public function test_null_facility_manager_cannot_create_department(): void
    {
        $facility = Facility::factory()->create();
        $this->nullFacilityManager();

        $this->postJson('/api/departments', [
            'facility_id'  => $facility->id,
            'external_key' => 'DEPT-NF',
            'name'         => 'NF Dept',
        ])->assertStatus(403);
    }

    /* ---- doctor create denied ---- */

    public function test_null_facility_manager_cannot_create_doctor(): void
    {
        $facility = Facility::factory()->create();
        $this->nullFacilityManager();

        $this->postJson('/api/doctors', [
            'facility_id'    => $facility->id,
            'external_key'   => 'DOC-NF-01',
            'first_name'     => 'No',
            'last_name'      => 'Facility',
        ])->assertStatus(403);
    }

    /* ---- doctor show denied by policy ---- */

    public function test_null_facility_non_admin_cannot_view_doctor(): void
    {
        $facility = Facility::factory()->create();
        $doctor = Doctor::factory()->create(['facility_id' => $facility->id]);
        $this->nullFacilityClerk();

        $this->getJson("/api/doctors/{$doctor->id}")->assertStatus(403);
    }

    /* ---- patient create denied ---- */

    public function test_null_facility_manager_cannot_create_patient(): void
    {
        $facility = Facility::factory()->create();
        $this->nullFacilityManager();

        $this->postJson('/api/patients', [
            'facility_id'  => $facility->id,
            'external_key' => 'PAT-NF-01',
            'name'         => 'Ghost Pet',
        ])->assertStatus(403);
    }

    /* ---- visit create denied ---- */

    public function test_null_facility_non_admin_cannot_create_visit(): void
    {
        $facility = Facility::factory()->create();
        $patient = Patient::factory()->create(['facility_id' => $facility->id]);
        $doctor = Doctor::factory()->create(['facility_id' => $facility->id]);
        $this->nullFacilityClerk();

        $this->postJson('/api/visits', [
            'facility_id' => $facility->id,
            'patient_id'  => $patient->id,
            'doctor_id'   => $doctor->id,
            'visit_date'  => now()->addDay()->toIso8601String(),
            'status'      => 'scheduled',
        ])->assertStatus(403);
    }

    /* ---- service-order create denied ---- */

    public function test_null_facility_non_admin_cannot_create_service_order(): void
    {
        $facility = Facility::factory()->create();
        $user = User::factory()->create(['role' => 'technician_doctor', 'facility_id' => null]);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/service-orders', [
            'facility_id'          => $facility->id,
            'reservation_strategy' => 'deduct_at_close',
        ])->assertStatus(403);
    }

    /* ---- rental transaction show denied by policy ---- */

    public function test_null_facility_non_admin_cannot_view_rental_transaction(): void
    {
        $facility = Facility::factory()->create();
        $asset = RentalAsset::factory()->create(['facility_id' => $facility->id]);
        $tx = RentalTransaction::factory()->create([
            'asset_id'    => $asset->id,
            'facility_id' => $facility->id,
            'status'      => 'active',
        ]);
        $this->nullFacilityClerk();

        $this->getJson("/api/rental-transactions/{$tx->id}")->assertStatus(403);
    }

    /* ---- visit-review object denied by policy ---- */

    public function test_null_facility_non_admin_cannot_view_visit_review(): void
    {
        $review = VisitReview::factory()->create();
        $this->nullFacilityManager();

        $this->getJson("/api/reviews/{$review->id}")->assertStatus(403);
    }

    /* ---- review dashboard denied ---- */

    public function test_null_facility_manager_review_dashboard_is_denied(): void
    {
        $this->nullFacilityManager();

        $this->getJson('/api/reviews/dashboard')->assertStatus(403);
    }

    /* ---- low-stock alerts denied ---- */

    public function test_null_facility_clerk_low_stock_alerts_is_denied(): void
    {
        $this->nullFacilityClerk();

        $this->getJson('/api/inventory/low-stock-alerts')->assertStatus(403);
    }

    /* ---- content index returns empty set for null-facility editor ---- */

    public function test_null_facility_editor_content_list_is_empty(): void
    {
        $facility = Facility::factory()->create();
        ContentItem::factory()->create([
            'facility_ids' => [$facility->id],
            'status'       => 'published',
        ]);

        $this->nullFacilityEditor();

        $this->getJson('/api/content')->assertStatus(200)->assertJsonPath('data', []);
    }

    /* ---- service pricing list returns empty set for null-facility manager ---- */

    public function test_null_facility_manager_service_pricings_list_is_empty(): void
    {
        $facility = Facility::factory()->create();
        $service = \App\Models\Service::factory()->create();
        \App\Models\ServicePricing::factory()->create([
            'service_id'  => $service->id,
            'facility_id' => $facility->id,
        ]);

        $this->nullFacilityManager();

        $response = $this->getJson("/api/services/{$service->id}/pricings");
        $response->assertStatus(200);
        $this->assertEmpty($response->json());
    }

    /* ---- service pricing create denied for null-facility manager ---- */

    public function test_null_facility_manager_cannot_create_service_pricing(): void
    {
        $facility = Facility::factory()->create();
        $service = \App\Models\Service::factory()->create();
        $this->nullFacilityManager();

        $this->postJson("/api/services/{$service->id}/pricings", [
            'facility_id'    => $facility->id,
            'base_price'     => 50.00,
            'effective_from' => now()->toDateString(),
        ])->assertStatus(403);
    }

    /* ---- facility index list returns empty set for null-facility user ---- */

    public function test_null_facility_non_admin_facility_list_is_empty(): void
    {
        Facility::factory()->count(3)->create();
        $this->nullFacilityManager();

        $response = $this->getJson('/api/facilities');
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    /* ---- stock levels returns empty set for null-facility user ---- */

    public function test_null_facility_clerk_stock_levels_is_empty(): void
    {
        $facility = Facility::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $facility->id]);
        \App\Models\InventoryItem::factory()->create();

        $this->nullFacilityClerk();

        $response = $this->getJson('/api/inventory/stock-levels');
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    /* ---- ledger returns empty set for null-facility user ---- */

    public function test_null_facility_clerk_ledger_is_empty(): void
    {
        $facility = Facility::factory()->create();
        Storeroom::factory()->create(['facility_id' => $facility->id]);

        $this->nullFacilityClerk();

        $response = $this->getJson('/api/inventory/ledger');
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    /* ---- stocktake list returns empty set for null-facility user ---- */

    public function test_null_facility_clerk_stocktake_list_is_empty(): void
    {
        $facility = Facility::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $facility->id]);
        \App\Models\StocktakeSession::create([
            'storeroom_id' => $storeroom->id,
            'status'       => 'open',
            'started_by'   => User::factory()->create()->id,
            'started_at'   => now(),
        ]);

        $this->nullFacilityClerk();

        $response = $this->getJson('/api/stocktake');
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    /* ---- overdue rental list returns empty array for null-facility user ---- */

    public function test_null_facility_clerk_overdue_list_is_empty(): void
    {
        $facility = Facility::factory()->create();
        $asset = RentalAsset::factory()->create(['facility_id' => $facility->id]);
        RentalTransaction::factory()->create([
            'asset_id'           => $asset->id,
            'facility_id'        => $facility->id,
            'status'             => 'overdue',
            'expected_return_at' => now()->subDays(3),
        ]);

        $this->nullFacilityClerk();

        $response = $this->getJson('/api/rental-transactions/overdue');
        $response->assertStatus(200);
        $this->assertEmpty($response->json());
    }
}
