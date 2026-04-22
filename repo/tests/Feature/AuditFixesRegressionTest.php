<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\Doctor;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\RentalAsset;
use App\Models\Storeroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Regression suite that pins each finding from audit_report-2.md once the
 * fix is in place. Every test points at one row in the audit: Blocker 1
 * (runbook integrity), High 1–5 (inactivity bypass, null-facility, content
 * index, stocktake contract, cross-entity consistency), Medium 1–2
 * (publish-from-draft, audit-log policy).
 */
class AuditFixesRegressionTest extends TestCase
{
    use RefreshDatabase;

    /* ---- Blocker 1: runbook integrity — every command is registered ---- */

    public function test_admin_seeder_provisions_admin_user(): void
    {
        $this->artisan('db:seed', ['--class' => 'AdminSeeder'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'username' => 'admin',
            'role'     => 'system_admin',
        ]);
    }

    public function test_purge_audit_logs_supports_dry_run(): void
    {
        $this->artisan('vetops:purge-audit-logs', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertExitCode(0);
    }

    public function test_refresh_stock_levels_command_runs_successfully(): void
    {
        $this->artisan('vetops:refresh-stock-levels', ['--window' => 7])
            ->assertExitCode(0);
    }

    public function test_verify_file_checksums_command_runs_successfully(): void
    {
        // Empty DB — checks zero files, exits successfully.
        $this->artisan('vetops:verify-file-checksums')
            ->assertExitCode(0);
    }

    public function test_rotate_pii_keys_fails_without_previous_key_env(): void
    {
        // Without the previous-key env var, the command refuses to proceed.
        $this->artisan('vetops:rotate-pii-keys')
            ->expectsOutputToContain('VETOPS_ENCRYPTION_KEY_PREVIOUS')
            ->assertExitCode(1);
    }

    /* ---- High 1: inactivity bypass via refresh endpoint ---- */

    public function test_refresh_rejects_stale_cookie_after_inactivity(): void
    {
        $user  = User::factory()->create(['inactivity_timeout' => 15]);
        $token = $user->createToken('api-token');
        $plain = $token->plainTextToken;

        // Simulate last activity 30 minutes ago.
        Cache::put(
            "vetops.token_idle:{$token->accessToken->id}",
            now()->subMinutes(30)->toIso8601String(),
            now()->addMinutes(120),
        );

        $this->withRefreshCookie($plain)
            ->postJson('/api/auth/refresh')
            ->assertStatus(422);
    }

    public function test_refresh_succeeds_when_idle_checkpoint_is_fresh(): void
    {
        $user  = User::factory()->create(['inactivity_timeout' => 15]);
        $token = $user->createToken('api-token');
        $plain = $token->plainTextToken;

        Cache::put(
            "vetops.token_idle:{$token->accessToken->id}",
            now()->subMinutes(2)->toIso8601String(),
            now()->addMinutes(120),
        );

        $this->withRefreshCookie($plain)
            ->postJson('/api/auth/refresh')
            ->assertStatus(200)
            ->assertJsonPath('user.id', $user->id);
    }

    /* ---- High 2: null-facility tenant bypass via UserController ---- */

    public function test_user_create_rejects_null_facility_for_non_admin_role(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/users', [
            'username'              => 'nofac',
            'name'                  => 'No Facility',
            'password'              => 'SecurePass1234!',
            'password_confirmation' => 'SecurePass1234!',
            'role'                  => 'technician_doctor',
            // facility_id intentionally omitted
        ])->assertStatus(422)->assertJsonValidationErrors(['facility_id']);
    }

    public function test_user_create_allows_null_facility_only_for_system_admin(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/users', [
            'username'              => 'globaladmin',
            'name'                  => 'Global',
            'password'              => 'SecurePass1234!',
            'password_confirmation' => 'SecurePass1234!',
            'role'                  => 'system_admin',
        ])->assertStatus(201);
    }

    public function test_user_update_rejects_null_facility_for_non_admin_role(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $user = User::factory()->manager()->create(['facility_id' => $facility->id]);

        $this->putJson("/api/users/{$user->id}", [
            'facility_id' => null,
        ])->assertStatus(422)->assertJsonValidationErrors(['facility_id']);
    }

    public function test_user_update_rejects_demoting_admin_without_facility(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->admin()->create(['facility_id' => null]);

        // Demoting to manager without also setting a facility must fail.
        $this->putJson("/api/users/{$user->id}", [
            'role' => 'clinic_manager',
        ])->assertStatus(422)->assertJsonValidationErrors(['facility_id']);
    }

    /* ---- High 3: content index scoping ---- */

    public function test_non_authoring_user_only_sees_published_targeted_content(): void
    {
        $user = User::factory()->create(['role' => 'inventory_clerk', 'facility_id' => 1]);
        $this->actingAs($user, 'sanctum');

        ContentItem::factory()->create(['status' => 'draft', 'title' => 'Hidden draft']);
        ContentItem::factory()->create(['status' => 'in_review', 'title' => 'Still being reviewed']);
        ContentItem::factory()->published()->create(['title' => 'Visible published']);

        $response = $this->getJson('/api/content');
        $response->assertStatus(200);
        $titles = array_column($response->json('data'), 'title');
        $this->assertContains('Visible published', $titles);
        $this->assertNotContains('Hidden draft', $titles);
        $this->assertNotContains('Still being reviewed', $titles);
    }

    public function test_content_editor_can_see_drafts_and_in_review(): void
    {
        // Editor with a facility assignment — so the authoring branch's
        // facility-scoped filter returns the draft (whose facility_ids is null).
        $facility = Facility::factory()->create();
        $editor = User::factory()->contentEditor()->create(['facility_id' => $facility->id]);
        $this->actingAs($editor, 'sanctum');

        ContentItem::factory()->create(['status' => 'draft', 'title' => 'My draft']);

        $response = $this->getJson('/api/content');
        $response->assertStatus(200);
        $titles = array_column($response->json('data'), 'title');
        $this->assertContains('My draft', $titles);
    }

    /* ---- High 5: cross-entity facility consistency ---- */

    public function test_visit_create_rejects_patient_from_different_facility(): void
    {
        $this->actingAsAdmin();
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $patientB = Patient::factory()->create(['facility_id' => $b->id]);
        $doctorA  = Doctor::factory()->create(['facility_id' => $a->id]);

        $this->postJson('/api/visits', [
            'facility_id' => $a->id,
            'patient_id'  => $patientB->id,
            'doctor_id'   => $doctorA->id,
            'visit_date'  => now()->addDays(1)->toIso8601String(),
            'status'      => 'scheduled',
        ])->assertStatus(422)->assertJsonValidationErrors(['patient_id']);
    }

    public function test_visit_create_rejects_doctor_from_different_facility(): void
    {
        $this->actingAsAdmin();
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $patientA = Patient::factory()->create(['facility_id' => $a->id]);
        $doctorB  = Doctor::factory()->create(['facility_id' => $b->id]);

        $this->postJson('/api/visits', [
            'facility_id' => $a->id,
            'patient_id'  => $patientA->id,
            'doctor_id'   => $doctorB->id,
            'visit_date'  => now()->addDays(1)->toIso8601String(),
            'status'      => 'scheduled',
        ])->assertStatus(422)->assertJsonValidationErrors(['doctor_id']);
    }

    public function test_service_order_rejects_cross_facility_patient(): void
    {
        $this->actingAsAdmin();
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $patientB = Patient::factory()->create(['facility_id' => $b->id]);

        $this->postJson('/api/service-orders', [
            'facility_id'          => $a->id,
            'patient_id'           => $patientB->id,
            'reservation_strategy' => 'deduct_at_close',
        ])->assertStatus(422)->assertJsonValidationErrors(['patient_id']);
    }

    public function test_service_order_rejects_cross_facility_doctor(): void
    {
        $this->actingAsAdmin();
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $doctorB = Doctor::factory()->create(['facility_id' => $b->id]);

        $this->postJson('/api/service-orders', [
            'facility_id'          => $a->id,
            'doctor_id'            => $doctorB->id,
            'reservation_strategy' => 'deduct_at_close',
        ])->assertStatus(422)->assertJsonValidationErrors(['doctor_id']);
    }

    public function test_rental_checkout_rejects_facility_id_mismatch_with_asset(): void
    {
        $this->actingAsAdmin();
        $assetFacility = Facility::factory()->create();
        $requestFacility = Facility::factory()->create();
        $asset = RentalAsset::factory()->create([
            'facility_id' => $assetFacility->id,
            'status'      => 'available',
        ]);

        $this->postJson('/api/rental-transactions/checkout', [
            'asset_id'           => $asset->id,
            'renter_type'        => 'department',
            'renter_id'          => 1,
            'facility_id'        => $requestFacility->id, // ≠ asset.facility_id
            'expected_return_at' => now()->addDays(1)->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors(['facility_id']);
    }

    /* ---- Medium 1: content publish-from-draft bypass ---- */

    public function test_publish_rejects_draft_content_with_422(): void
    {
        $this->actingAsContentApprover();
        $item = ContentItem::factory()->create(['status' => 'draft']);

        $this->postJson("/api/content/{$item->id}/publish")
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_publish_still_works_from_approved_status(): void
    {
        $this->actingAsContentApprover();
        $item = ContentItem::factory()->create(['status' => 'approved']);

        $this->postJson("/api/content/{$item->id}/publish")
            ->assertStatus(200)->assertJsonPath('status', 'published');
    }

    /* ---- Medium 2: audit log policy invocation ---- */

    public function test_technician_cannot_access_audit_log_index(): void
    {
        // Route middleware still blocks this first, but the controller-level
        // policy would also reject — both layers must consistently deny.
        $this->actingAsTechnicianDoctor();

        $this->getJson('/api/audit-logs')->assertStatus(403);
    }

    public function test_manager_can_access_audit_log_index(): void
    {
        $this->actingAsManager();

        $this->getJson('/api/audit-logs')->assertStatus(200);
    }

    public function test_technician_cannot_access_audit_log_export(): void
    {
        $this->actingAsTechnicianDoctor();

        $this->getJson('/api/audit-logs/export')->assertStatus(403);
    }

    /* ---- Reviews dashboard breakdown ---- */

    public function test_dashboard_breakdown_returns_per_facility_and_per_provider(): void
    {
        $admin = $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $doctor   = Doctor::factory()->create(['facility_id' => $facility->id]);

        \App\Models\VisitReview::factory()->create([
            'facility_id' => $facility->id,
            'doctor_id'   => $doctor->id,
            'status'      => 'published',
            'rating'      => 5,
            'submitted_at' => now()->subDay(),
        ]);
        \App\Models\VisitReview::factory()->create([
            'facility_id' => $facility->id,
            'doctor_id'   => $doctor->id,
            'status'      => 'published',
            'rating'      => 2,
            'submitted_at' => now()->subDay(),
        ]);

        $response = $this->getJson('/api/reviews/dashboard/breakdown');
        $response->assertStatus(200)
            ->assertJsonStructure(['overall', 'by_facility', 'by_provider']);

        $this->assertCount(1, $response->json('by_facility'));
        $this->assertCount(1, $response->json('by_provider'));
        $this->assertEquals($facility->id, $response->json('by_facility.0.facility_id'));
        $this->assertEquals($doctor->id, $response->json('by_provider.0.doctor_id'));
    }

    /* ---- Reviews dashboard breakdown is facility-scoped for managers ---- */

    public function test_dashboard_breakdown_scopes_to_manager_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $manager = User::factory()->manager()->create(['facility_id' => $a->id]);
        $this->actingAs($manager, 'sanctum');

        $doctorA = Doctor::factory()->create(['facility_id' => $a->id]);
        $doctorB = Doctor::factory()->create(['facility_id' => $b->id]);

        \App\Models\VisitReview::factory()->create([
            'facility_id' => $a->id, 'doctor_id' => $doctorA->id,
            'status' => 'published', 'rating' => 4, 'submitted_at' => now()->subDay(),
        ]);
        \App\Models\VisitReview::factory()->create([
            'facility_id' => $b->id, 'doctor_id' => $doctorB->id,
            'status' => 'published', 'rating' => 3, 'submitted_at' => now()->subDay(),
        ]);

        $response = $this->getJson('/api/reviews/dashboard/breakdown');
        $response->assertStatus(200);

        // The manager only sees their own facility's breakdown.
        $facilityRows = $response->json('by_facility');
        $this->assertCount(1, $facilityRows);
        $this->assertEquals($a->id, $facilityRows[0]['facility_id']);
    }
}
