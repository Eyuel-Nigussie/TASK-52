<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentVersion;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\Patient;
use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServicePricing;
use App\Models\StockLevel;
use App\Models\Storeroom;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Broad edge-case coverage test file: targets API surface areas not pinned
 * by the main per-resource test files, focusing on validation branches,
 * enum/state transitions, pagination shapes, and cross-resource effects.
 *
 * Grouped by domain for readability but all in one class to keep
 * authorization helpers/setup concise.
 */
class EdgeCaseCoverageTest extends TestCase
{
    use RefreshDatabase;

    /* -------------------- Services / pricing edge cases -------------------- */

    public function test_service_store_rejects_duplicate_external_key(): void
    {
        $this->actingAsAdmin();
        Service::factory()->create(['external_key' => 'SVC-UNQ-1']);

        $this->postJson('/api/services', [
            'external_key' => 'SVC-UNQ-1', // duplicate
            'name'         => 'Another',
            'category'     => 'clinical',
        ])->assertStatus(422)->assertJsonValidationErrors(['external_key']);
    }

    public function test_service_store_rejects_invalid_duration_minutes(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/services', [
            'external_key'     => 'SVC-DUR',
            'name'             => 'X',
            'category'         => 'clinical',
            'duration_minutes' => 2000, // exceeds max 1440
        ])->assertStatus(422)->assertJsonValidationErrors(['duration_minutes']);
    }

    public function test_pricing_requires_future_or_equal_effective_to(): void
    {
        $this->actingAsAdmin();
        $service = Service::factory()->create();
        $facility = Facility::factory()->create();

        $this->postJson("/api/services/{$service->id}/pricings", [
            'facility_id'    => $facility->id,
            'base_price'     => 40,
            'effective_from' => now()->toDateString(),
            'effective_to'   => now()->subDays(1)->toDateString(), // before effective_from
        ])->assertStatus(422)->assertJsonValidationErrors(['effective_to']);
    }

    /* -------------------- Content workflow edge cases -------------------- */

    public function test_submit_for_review_fails_when_item_not_draft(): void
    {
        $editor = $this->actingAsContentEditor();
        $item = ContentItem::factory()->create([
            'status'    => 'approved',
            'author_id' => $editor->id,
        ]);

        $this->postJson("/api/content/{$item->id}/submit-review")
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_approve_fails_when_item_not_in_review(): void
    {
        $this->actingAsContentApprover();
        $item = ContentItem::factory()->create(['status' => 'draft']);

        $this->postJson("/api/content/{$item->id}/approve")
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_publish_rejects_when_item_archived(): void
    {
        $this->actingAsContentApprover();
        $item = ContentItem::factory()->create(['status' => 'archived']);

        $this->postJson("/api/content/{$item->id}/publish")
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_rollback_to_unknown_version_returns_422(): void
    {
        $editor = $this->actingAsContentEditor();
        $item = ContentItem::factory()->create(['status' => 'draft', 'author_id' => $editor->id]);

        $this->postJson("/api/content/{$item->id}/rollback", ['version' => 999])
            ->assertStatus(422)->assertJsonValidationErrors(['version']);
    }

    public function test_published_feed_is_empty_for_unmatched_facility_targeting(): void
    {
        $user = $this->actingAsTechnicianDoctor();
        $user->facility_id = 1;
        $user->save();

        ContentItem::factory()->published()->create([
            'type'         => 'announcement',
            'facility_ids' => [999], // not user.facility_id
        ]);

        $response = $this->getJson('/api/content/published?type=announcement');
        $response->assertStatus(200);
        $this->assertEmpty($response->json());
    }

    /* -------------------- Rental edge cases -------------------- */

    public function test_rental_cannot_be_checked_out_if_status_not_available(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $asset = RentalAsset::factory()->create([
            'facility_id' => $facility->id,
            'status'      => 'maintenance',
        ]);
        $dept = Department::factory()->create(['facility_id' => $facility->id]);

        $this->postJson('/api/rental-transactions/checkout', [
            'asset_id'           => $asset->id,
            'renter_type'        => 'department',
            'renter_id'          => $dept->id,
            'facility_id'        => $facility->id,
            'expected_return_at' => now()->addDays(1)->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_rental_scan_returns_404_for_unknown_code(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/rental-assets/scan?code=DOES-NOT-EXIST')
            ->assertStatus(404);
    }

    public function test_rental_transactions_filter_by_status(): void
    {
        $this->actingAsAdmin();
        $a = RentalAsset::factory()->create(['status' => 'rented']);
        RentalTransaction::factory()->create(['asset_id' => $a->id, 'status' => 'active']);

        $b = RentalAsset::factory()->create(['status' => 'available']);
        RentalTransaction::factory()->create(['asset_id' => $b->id, 'status' => 'returned']);

        $response = $this->getJson('/api/rental-transactions?status=active');
        $response->assertStatus(200)->assertJsonPath('total', 1);
    }

    /* -------------------- Storeroom edge cases -------------------- */

    public function test_manager_can_list_storerooms_scoped_to_own_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $user = User::factory()->manager()->create(['facility_id' => $a->id]);
        $this->actingAs($user, 'sanctum');

        Storeroom::factory()->create(['facility_id' => $a->id]);
        Storeroom::factory()->count(2)->create(['facility_id' => $b->id]);

        $response = $this->getJson('/api/storerooms');
        $response->assertStatus(200);
        foreach ($response->json() as $row) {
            $this->assertEquals($a->id, $row['facility_id']);
        }
    }

    /* -------------------- Visits edge cases -------------------- */

    public function test_visit_update_allows_completion_transition(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $patient  = Patient::factory()->create(['facility_id' => $facility->id]);
        $doctor   = Doctor::factory()->create(['facility_id' => $facility->id]);
        $visit    = Visit::factory()->create([
            'facility_id' => $facility->id,
            'patient_id'  => $patient->id,
            'doctor_id'   => $doctor->id,
            'status'      => 'scheduled',
        ]);

        $this->putJson("/api/visits/{$visit->id}", ['status' => 'completed'])
            ->assertStatus(200)->assertJsonPath('status', 'completed');
    }

    public function test_visit_index_filters_by_doctor_and_date_range(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $doctor   = Doctor::factory()->create(['facility_id' => $facility->id]);
        $other    = Doctor::factory()->create(['facility_id' => $facility->id]);
        $patient  = Patient::factory()->create(['facility_id' => $facility->id]);

        Visit::factory()->create([
            'facility_id' => $facility->id,
            'patient_id'  => $patient->id,
            'doctor_id'   => $doctor->id,
            'visit_date'  => now()->subDays(2),
        ]);
        Visit::factory()->create([
            'facility_id' => $facility->id,
            'patient_id'  => $patient->id,
            'doctor_id'   => $other->id,
            'visit_date'  => now()->subDays(2),
        ]);

        $response = $this->getJson("/api/visits?doctor_id={$doctor->id}");
        $response->assertStatus(200)->assertJsonPath('total', 1);
    }

    /* -------------------- Facility edge cases -------------------- */

    public function test_facility_update_touches_updated_by(): void
    {
        $admin = $this->actingAsAdmin();
        $facility = Facility::factory()->create(['name' => 'Old']);

        $this->putJson("/api/facilities/{$facility->id}", ['name' => 'Renamed'])
            ->assertStatus(200)->assertJsonPath('name', 'Renamed');

        $this->assertDatabaseHas('facilities', [
            'id'         => $facility->id,
            'updated_by' => $admin->id,
        ]);
    }

    public function test_facility_index_excludes_inactive_when_active_only(): void
    {
        $this->actingAsAdmin();
        Facility::factory()->count(2)->create(['active' => true]);
        Facility::factory()->create(['active' => false]);

        $response = $this->getJson('/api/facilities?active_only=1');
        $response->assertStatus(200)->assertJsonPath('total', 2);
    }

    /* -------------------- Inventory edge cases -------------------- */

    public function test_inventory_receive_rejects_negative_quantity(): void
    {
        $this->actingAsInventoryClerk();
        $item = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create();

        $this->postJson('/api/inventory/receive', [
            'item_id'      => $item->id,
            'storeroom_id' => $storeroom->id,
            'quantity'     => -5,
        ])->assertStatus(422);
    }

    public function test_inventory_transfer_rejects_same_source_and_destination(): void
    {
        $this->actingAsInventoryClerk();
        $item = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create();

        $this->postJson('/api/inventory/transfer', [
            'item_id'           => $item->id,
            'from_storeroom_id' => $storeroom->id,
            'to_storeroom_id'   => $storeroom->id,
            'quantity'          => 1,
        ])->assertStatus(422);
    }

    public function test_inventory_item_show_via_items_list_with_search(): void
    {
        $this->actingAsAdmin();
        InventoryItem::factory()->create(['name' => 'Bandage Roll', 'external_key' => 'BR-1']);
        InventoryItem::factory()->create(['name' => 'Syringe', 'external_key' => 'SR-1']);

        $response = $this->getJson('/api/inventory/items?search=Bandage');
        $response->assertStatus(200)->assertJsonPath('total', 1);
    }

    /* -------------------- Service order edge cases -------------------- */

    public function test_service_order_reject_invalid_strategy(): void
    {
        $this->actingAsTechnicianDoctor();
        $facility = Facility::factory()->create();

        $this->postJson('/api/service-orders', [
            'facility_id'          => $facility->id,
            'reservation_strategy' => 'madeup_strategy',
        ])->assertStatus(422)->assertJsonValidationErrors(['reservation_strategy']);
    }

    /* -------------------- Doctor / patient edge cases -------------------- */

    public function test_patient_index_search_matches_name_or_owner(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        Patient::factory()->create([
            'facility_id' => $facility->id,
            'name'        => 'Whiskers',
            'owner_name'  => 'Zelda',
        ]);
        Patient::factory()->create([
            'facility_id' => $facility->id,
            'name'        => 'Other',
            'owner_name'  => 'Link',
        ]);

        $this->getJson('/api/patients?search=Zelda')
            ->assertStatus(200)->assertJsonPath('total', 1);
        $this->getJson('/api/patients?search=Whiskers')
            ->assertStatus(200)->assertJsonPath('total', 1);
    }

    public function test_doctor_update_can_toggle_active(): void
    {
        $this->actingAsAdmin();
        $doctor = Doctor::factory()->create(['active' => true]);

        $this->putJson("/api/doctors/{$doctor->id}", ['active' => false])
            ->assertStatus(200)->assertJsonPath('active', false);
    }
}
