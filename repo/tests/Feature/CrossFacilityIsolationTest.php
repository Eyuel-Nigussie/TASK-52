<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant-isolation regression suite. A manager/clerk/tech bound to facility A
 * must not be able to read or mutate facility B's records by guessing an id.
 * `system_admin` retains cross-facility access by design (§RBAC.md).
 */
class CrossFacilityIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsManagerOf(Facility $facility): User
    {
        $user = User::factory()->manager()->create(['facility_id' => $facility->id]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    private function actingAsClerkOf(Facility $facility): User
    {
        $user = User::factory()->inventoryClerk()->create(['facility_id' => $facility->id]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    public function test_cannot_view_patient_from_other_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsManagerOf($a);

        $foreign = Patient::factory()->create(['facility_id' => $b->id]);

        $this->getJson("/api/patients/{$foreign->id}")->assertStatus(403);
    }

    public function test_cannot_update_patient_from_other_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsManagerOf($a);

        $foreign = Patient::factory()->create(['facility_id' => $b->id]);

        $this->putJson("/api/patients/{$foreign->id}", ['name' => 'hacked'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('patients', ['id' => $foreign->id, 'name' => 'hacked']);
    }

    public function test_patient_list_is_scoped_to_caller_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsManagerOf($a);

        Patient::factory()->create(['facility_id' => $a->id, 'name' => 'Mine']);
        Patient::factory()->create(['facility_id' => $b->id, 'name' => 'Theirs']);

        // Even with an explicit facility_id=B param, the user is locked to A.
        $response = $this->getJson('/api/patients?facility_id=' . $b->id);
        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }

    public function test_cannot_view_visit_from_other_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsManagerOf($a);

        $doctor = Doctor::factory()->create(['facility_id' => $b->id]);
        $patient = Patient::factory()->create(['facility_id' => $b->id]);
        $visit = Visit::factory()->create([
            'facility_id' => $b->id,
            'doctor_id'   => $doctor->id,
            'patient_id'  => $patient->id,
            'status'      => 'completed',
        ]);

        $this->getJson("/api/visits/{$visit->id}")->assertStatus(403);
    }

    public function test_cannot_view_review_from_other_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsManagerOf($a);

        $doctor = Doctor::factory()->create(['facility_id' => $b->id]);
        $patient = Patient::factory()->create(['facility_id' => $b->id]);
        $visit = Visit::factory()->create([
            'facility_id' => $b->id,
            'doctor_id'   => $doctor->id,
            'patient_id'  => $patient->id,
            'status'      => 'completed',
        ]);
        $review = VisitReview::factory()->create([
            'visit_id'    => $visit->id,
            'facility_id' => $b->id,
            'doctor_id'   => $doctor->id,
            'status'      => 'published',
        ]);

        $this->getJson("/api/reviews/{$review->id}")->assertStatus(403);

        // Mutating actions on another facility's review must also fail.
        $this->postJson("/api/reviews/{$review->id}/hide", ['reason' => 'cross-facility attempt'])
            ->assertStatus(403);
    }

    public function test_cannot_cancel_rental_from_other_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsManagerOf($a);

        $asset = RentalAsset::factory()->create(['facility_id' => $b->id, 'status' => 'rented']);
        $tx = RentalTransaction::factory()->create([
            'asset_id'    => $asset->id,
            'facility_id' => $b->id,
            'status'      => 'active',
        ]);

        $this->postJson("/api/rental-transactions/{$tx->id}/cancel")->assertStatus(403);

        $this->assertDatabaseHas('rental_transactions', ['id' => $tx->id, 'status' => 'active']);
    }

    public function test_cannot_checkout_asset_from_other_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsClerkOf($a);

        $asset = RentalAsset::factory()->create(['facility_id' => $b->id, 'status' => 'available']);

        $response = $this->postJson('/api/rental-transactions/checkout', [
            'asset_id'           => $asset->id,
            'renter_type'        => 'department',
            'renter_id'          => 1,
            'facility_id'        => $b->id,
            'expected_return_at' => now()->addDays(1)->toIso8601String(),
        ]);

        $response->assertStatus(403);
    }

    public function test_cannot_view_service_order_from_other_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsManagerOf($a);

        $order = ServiceOrder::create([
            'facility_id'          => $b->id,
            'status'               => 'open',
            'reservation_strategy' => 'lock_at_creation',
            'created_by'           => User::factory()->manager()->create(['facility_id' => $b->id])->id,
        ]);

        $this->getJson("/api/service-orders/{$order->id}")->assertStatus(403);
    }

    public function test_system_admin_can_access_any_facility(): void
    {
        $a = Facility::factory()->create();
        $this->actingAsAdmin();

        $patient = Patient::factory()->create(['facility_id' => $a->id]);

        $this->getJson("/api/patients/{$patient->id}")->assertStatus(200);
    }

    public function test_cannot_view_foreign_facility_record(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsManagerOf($a);

        $this->getJson("/api/facilities/{$b->id}")->assertStatus(403);
    }

    public function test_facility_list_is_scoped_for_manager(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsManagerOf($a);

        $response = $this->getJson('/api/facilities');
        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($a->id));
        $this->assertFalse($ids->contains($b->id));
    }

    public function test_cannot_read_foreign_facility_history(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsManagerOf($a);

        $this->getJson("/api/facilities/{$b->id}/history")->assertStatus(403);
    }
}
