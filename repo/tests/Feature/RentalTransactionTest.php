<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Facility;
use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_checkout_available_asset(): void
    {
        $admin = $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $asset = RentalAsset::factory()->create(['facility_id' => $facility->id, 'status' => 'available']);
        $dept = Department::factory()->create(['facility_id' => $facility->id]);

        $response = $this->postJson('/api/rental-transactions/checkout', [
            'asset_id'           => $asset->id,
            'renter_type'        => 'department',
            'renter_id'          => $dept->id,
            'facility_id'        => $facility->id,
            'expected_return_at' => now()->addDays(3)->toIso8601String(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('rental_assets', ['id' => $asset->id, 'status' => 'rented']);
    }

    public function test_cannot_checkout_rented_asset_double_booking(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $asset = RentalAsset::factory()->create(['facility_id' => $facility->id, 'status' => 'rented']);
        $dept = Department::factory()->create(['facility_id' => $facility->id]);

        RentalTransaction::factory()->create([
            'asset_id' => $asset->id,
            'status'   => 'active',
        ]);

        $response = $this->postJson('/api/rental-transactions/checkout', [
            'asset_id'           => $asset->id,
            'renter_type'        => 'department',
            'renter_id'          => $dept->id,
            'facility_id'        => $facility->id,
            'expected_return_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertStatus(422);
    }

    public function test_can_return_rented_asset(): void
    {
        $this->actingAsAdmin();
        $asset = RentalAsset::factory()->create(['status' => 'rented']);
        $transaction = RentalTransaction::factory()->create([
            'asset_id'           => $asset->id,
            'status'             => 'active',
            'expected_return_at' => now()->addDays(1),
        ]);

        $response = $this->postJson("/api/rental-transactions/{$transaction->id}/return");

        $response->assertStatus(200)->assertJsonPath('status', 'returned');
        $this->assertDatabaseHas('rental_assets', ['id' => $asset->id, 'status' => 'available']);
    }

    public function test_overdue_detection_works(): void
    {
        $this->actingAsAdmin();
        $asset = RentalAsset::factory()->create(['status' => 'rented']);
        $overdueHours = (int) config('vetops.overdue_hours', 2);

        $transaction = RentalTransaction::factory()->create([
            'asset_id'           => $asset->id,
            'status'             => 'active',
            'expected_return_at' => now()->subHours($overdueHours + 1),
        ]);

        $this->assertTrue($transaction->isOverdue());
    }

    public function test_mark_overdue_command_updates_status(): void
    {
        $asset = RentalAsset::factory()->create(['status' => 'rented']);
        $overdueHours = (int) config('vetops.overdue_hours', 2);

        RentalTransaction::factory()->create([
            'asset_id'           => $asset->id,
            'status'             => 'active',
            'expected_return_at' => now()->subHours($overdueHours + 1),
        ]);

        $this->artisan('vetops:mark-overdue-rentals')->assertSuccessful();
        $this->assertDatabaseHas('rental_transactions', ['asset_id' => $asset->id, 'status' => 'overdue']);
    }

    public function test_fee_calculated_correctly_on_return(): void
    {
        $this->actingAsAdmin();
        $asset = RentalAsset::factory()->create(['status' => 'rented', 'daily_rate' => 50.00]);
        $transaction = RentalTransaction::factory()->create([
            'asset_id'       => $asset->id,
            'status'         => 'active',
            'checked_out_at' => now()->subDays(3),
            'expected_return_at' => now()->addDay(),
        ]);

        $response = $this->postJson("/api/rental-transactions/{$transaction->id}/return");

        $response->assertStatus(200);
        $this->assertGreaterThan(0, (float) $response->json('fee_amount'));
    }

    public function test_transaction_list_shows_overdue_only(): void
    {
        $this->actingAsAdmin();
        $overdueHours = (int) config('vetops.overdue_hours', 2);

        $asset1 = RentalAsset::factory()->create(['status' => 'rented']);
        RentalTransaction::factory()->create([
            'asset_id' => $asset1->id,
            'status'   => 'active',
            'expected_return_at' => now()->subHours($overdueHours + 1),
        ]);

        $asset2 = RentalAsset::factory()->create(['status' => 'rented']);
        RentalTransaction::factory()->create([
            'asset_id' => $asset2->id,
            'status'   => 'active',
            'expected_return_at' => now()->addDays(5),
        ]);

        $response = $this->getJson('/api/rental-transactions?overdue_only=1');
        $response->assertStatus(200)->assertJsonPath('total', 1);
    }

    public function test_admin_can_cancel_active_transaction(): void
    {
        $this->actingAsAdmin();
        $asset = RentalAsset::factory()->create(['status' => 'rented']);
        $tx = RentalTransaction::factory()->create([
            'asset_id' => $asset->id,
            'status'   => 'active',
        ]);

        $response = $this->postJson("/api/rental-transactions/{$tx->id}/cancel");

        $response->assertStatus(200)->assertJsonPath('status', 'cancelled');
        $this->assertDatabaseHas('rental_assets', ['id' => $asset->id, 'status' => 'available']);
    }

    public function test_cannot_cancel_returned_transaction(): void
    {
        $this->actingAsAdmin();
        $asset = RentalAsset::factory()->create(['status' => 'available']);
        $tx = RentalTransaction::factory()->create([
            'asset_id' => $asset->id,
            'status'   => 'returned',
        ]);

        $response = $this->postJson("/api/rental-transactions/{$tx->id}/cancel");

        $response->assertStatus(422);
    }

    public function test_clerk_cannot_cancel_transaction(): void
    {
        $this->actingAsInventoryClerk();
        $asset = RentalAsset::factory()->create(['status' => 'rented']);
        $tx = RentalTransaction::factory()->create([
            'asset_id' => $asset->id,
            'status'   => 'active',
        ]);

        $response = $this->postJson("/api/rental-transactions/{$tx->id}/cancel");

        $response->assertStatus(403);
    }

    public function test_overdue_list_endpoint_returns_only_overdue_active_transactions(): void
    {
        $this->actingAsAdmin();
        $overdueHours = (int) config('vetops.overdue_hours', 2);

        // Two overdue active transactions
        $assetA = RentalAsset::factory()->create(['status' => 'rented']);
        $overdueA = RentalTransaction::factory()->create([
            'asset_id'           => $assetA->id,
            'status'             => 'active',
            'expected_return_at' => now()->subHours($overdueHours + 3),
        ]);

        $assetB = RentalAsset::factory()->create(['status' => 'rented']);
        $overdueB = RentalTransaction::factory()->create([
            'asset_id'           => $assetB->id,
            'status'             => 'overdue',
            'expected_return_at' => now()->subHours($overdueHours + 5),
        ]);

        // Not overdue — expected return is in the future
        $assetC = RentalAsset::factory()->create(['status' => 'rented']);
        $onTime = RentalTransaction::factory()->create([
            'asset_id'           => $assetC->id,
            'status'             => 'active',
            'expected_return_at' => now()->addDays(3),
        ]);

        // Already returned
        $assetD = RentalAsset::factory()->create(['status' => 'available']);
        RentalTransaction::factory()->create([
            'asset_id'           => $assetD->id,
            'status'             => 'returned',
            'expected_return_at' => now()->subDays(2),
        ]);

        $response = $this->getJson('/api/rental-transactions/overdue');

        $response->assertStatus(200);
        $ids = array_column($response->json(), 'id');
        $this->assertContains($overdueA->id, $ids);
        $this->assertContains($overdueB->id, $ids);
        $this->assertNotContains($onTime->id, $ids);
    }

    public function test_overdue_list_is_facility_scoped_for_non_admin(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $user = \App\Models\User::factory()->manager()->create(['facility_id' => $a->id]);
        $this->actingAs($user, 'sanctum');

        $overdueHours = (int) config('vetops.overdue_hours', 2);
        $pastDue = now()->subHours($overdueHours + 4);

        $assetA = RentalAsset::factory()->create(['facility_id' => $a->id, 'status' => 'rented']);
        $txA = RentalTransaction::factory()->create([
            'asset_id'           => $assetA->id,
            'facility_id'        => $a->id,
            'status'             => 'active',
            'expected_return_at' => $pastDue,
        ]);

        $assetB = RentalAsset::factory()->create(['facility_id' => $b->id, 'status' => 'rented']);
        $txB = RentalTransaction::factory()->create([
            'asset_id'           => $assetB->id,
            'facility_id'        => $b->id,
            'status'             => 'active',
            'expected_return_at' => $pastDue,
        ]);

        $response = $this->getJson('/api/rental-transactions/overdue');

        $response->assertStatus(200);
        $ids = array_column($response->json(), 'id');
        $this->assertContains($txA->id, $ids);
        $this->assertNotContains($txB->id, $ids);
    }

    public function test_show_transaction_includes_overdue_flag(): void
    {
        $this->actingAsAdmin();
        $asset = RentalAsset::factory()->create(['status' => 'rented']);
        $overdueHours = (int) config('vetops.overdue_hours', 2);
        $tx = RentalTransaction::factory()->create([
            'asset_id'           => $asset->id,
            'status'             => 'active',
            'expected_return_at' => now()->subHours($overdueHours + 1),
        ]);

        $response = $this->getJson("/api/rental-transactions/{$tx->id}");

        $response->assertStatus(200)
            ->assertJsonPath('is_overdue', true);
    }

    public function test_cannot_checkout_with_past_return_date(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $asset = RentalAsset::factory()->create(['facility_id' => $facility->id, 'status' => 'available']);
        $dept = Department::factory()->create(['facility_id' => $facility->id]);

        $response = $this->postJson('/api/rental-transactions/checkout', [
            'asset_id'           => $asset->id,
            'renter_type'        => 'department',
            'renter_id'          => $dept->id,
            'facility_id'        => $facility->id,
            'expected_return_at' => now()->subDay()->toIso8601String(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['expected_return_at']);
    }

    public function test_double_booking_prevented_after_first_checkout_commits(): void
    {
        // Simulates the scenario where a second request arrives after the first
        // checkout transaction commits. The locked re-fetch inside the transaction
        // ensures the second request sees the updated 'rented' status and active
        // booking record and returns 422 — regardless of concurrent timing.
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $asset    = RentalAsset::factory()->create(['facility_id' => $facility->id, 'status' => 'available']);
        $dept     = Department::factory()->create(['facility_id' => $facility->id]);

        $payload = [
            'asset_id'           => $asset->id,
            'renter_type'        => 'department',
            'renter_id'          => $dept->id,
            'facility_id'        => $facility->id,
            'expected_return_at' => now()->addDays(1)->toIso8601String(),
        ];

        // First checkout — succeeds.
        $this->postJson('/api/rental-transactions/checkout', $payload)
            ->assertStatus(201);

        // Second identical checkout — must fail.
        $this->postJson('/api/rental-transactions/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset_id']);

        // Only one active transaction should exist in the DB.
        $this->assertDatabaseCount('rental_transactions', 1);
        $this->assertDatabaseHas('rental_assets', ['id' => $asset->id, 'status' => 'rented']);
    }
}
