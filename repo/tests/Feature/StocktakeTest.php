<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\StockLevel;
use App\Models\StocktakeSession;
use App\Models\Storeroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StocktakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_start_stocktake_session(): void
    {
        $clerk     = $this->actingAsInventoryClerk();
        $storeroom = Storeroom::factory()->create(['facility_id' => $clerk->facility_id]);

        $response = $this->postJson('/api/stocktake/start', [
            'storeroom_id' => $storeroom->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('status', 'open');
    }

    public function test_cannot_start_duplicate_stocktake(): void
    {
        $clerk     = $this->actingAsInventoryClerk();
        $storeroom = Storeroom::factory()->create(['facility_id' => $clerk->facility_id]);

        StocktakeSession::create([
            'storeroom_id' => $storeroom->id,
            'status'       => 'open',
            'started_by'   => $clerk->id,
            'started_at'   => now(),
        ]);

        $response = $this->postJson('/api/stocktake/start', [
            'storeroom_id' => $storeroom->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_can_record_stocktake_entry(): void
    {
        $clerk     = $this->actingAsInventoryClerk();
        $item      = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $clerk->facility_id]);

        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => 100,
            'reserved'             => 0,
            'available_to_promise' => 100,
            'avg_daily_usage'      => 0,
        ]);

        $session = StocktakeSession::create([
            'storeroom_id' => $storeroom->id,
            'status'       => 'open',
            'started_by'   => $clerk->id,
            'started_at'   => now(),
        ]);

        $response = $this->postJson("/api/stocktake/{$session->id}/entries", [
            'item_id'          => $item->id,
            'counted_quantity' => 95,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('stocktake_entries', [
            'session_id'       => $session->id,
            'item_id'          => $item->id,
            'counted_quantity' => 95,
        ]);
    }

    public function test_variance_above_threshold_requires_approval(): void
    {
        $facility  = Facility::factory()->create();
        $item      = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $facility->id]);

        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => 100,
            'reserved'             => 0,
            'available_to_promise' => 100,
            'avg_daily_usage'      => 0,
        ]);

        $session = StocktakeSession::create([
            'storeroom_id' => $storeroom->id,
            'status'       => 'open',
            'started_by'   => 1,
            'started_at'   => now(),
        ]);

        $clerk = User::factory()->inventoryClerk()->create(['facility_id' => $facility->id]);
        $this->actingAs($clerk, 'sanctum');

        // 6% variance (above 5% threshold)
        $response = $this->postJson("/api/stocktake/{$session->id}/entries", [
            'item_id'          => $item->id,
            'counted_quantity' => 94,  // 6% below 100
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('stocktake_entries', [
            'session_id'        => $session->id,
            'requires_approval' => true,
        ]);
    }

    public function test_variance_below_threshold_does_not_require_approval(): void
    {
        $facility  = Facility::factory()->create();
        $item      = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $facility->id]);

        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => 100,
            'reserved'             => 0,
            'available_to_promise' => 100,
            'avg_daily_usage'      => 0,
        ]);

        $session = StocktakeSession::create([
            'storeroom_id' => $storeroom->id,
            'status'       => 'open',
            'started_by'   => 1,
            'started_at'   => now(),
        ]);

        $clerk = User::factory()->inventoryClerk()->create(['facility_id' => $facility->id]);
        $this->actingAs($clerk, 'sanctum');

        // 3% variance (below 5% threshold)
        $response = $this->postJson("/api/stocktake/{$session->id}/entries", [
            'item_id'          => $item->id,
            'counted_quantity' => 97,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('stocktake_entries', [
            'session_id'        => $session->id,
            'requires_approval' => false,
        ]);
    }

    public function test_manager_can_approve_individual_entry(): void
    {
        $manager   = $this->actingAsManager();
        $item      = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $manager->facility_id]);

        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => 100,
            'reserved'             => 0,
            'available_to_promise' => 100,
            'avg_daily_usage'      => 0,
        ]);

        $session = StocktakeSession::create([
            'storeroom_id' => $storeroom->id,
            'status'       => 'open',
            'started_by'   => $manager->id,
            'started_at'   => now(),
        ]);

        $entryResponse = $this->postJson("/api/stocktake/{$session->id}/entries", [
            'item_id'          => $item->id,
            'counted_quantity' => 80,  // 20% variance — requires approval
        ]);

        $entryId = $entryResponse->json('id');

        $response = $this->postJson("/api/stocktake/{$session->id}/entries/{$entryId}/approve", [
            'reason' => 'Verified by physical recount after system update',
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('approved_by'));
    }

    public function test_non_manager_cannot_approve_entry(): void
    {
        $clerk     = $this->actingAsInventoryClerk();
        $item      = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $clerk->facility_id]);

        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => 100,
            'reserved'             => 0,
            'available_to_promise' => 100,
            'avg_daily_usage'      => 0,
        ]);

        $session = StocktakeSession::create([
            'storeroom_id' => $storeroom->id,
            'status'       => 'open',
            'started_by'   => $clerk->id,
            'started_at'   => now(),
        ]);

        $entryResp = $this->postJson("/api/stocktake/{$session->id}/entries", [
            'item_id'          => $item->id,
            'counted_quantity' => 80,
        ]);
        $entryId = $entryResp->json('id');

        $response = $this->postJson("/api/stocktake/{$session->id}/entries/{$entryId}/approve", [
            'reason' => 'Trying to approve without rights',
        ]);

        $response->assertStatus(403);
    }

    public function test_manager_can_approve_session_after_all_entries_approved(): void
    {
        $manager   = $this->actingAsManager();
        $item      = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $manager->facility_id]);

        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => 100,
            'reserved'             => 0,
            'available_to_promise' => 100,
            'avg_daily_usage'      => 0,
        ]);

        $session = StocktakeSession::create([
            'storeroom_id' => $storeroom->id,
            'status'       => 'open',
            'started_by'   => $manager->id,
            'started_at'   => now(),
        ]);

        // Add entry with high variance (not yet approved).
        $entryResp = $this->postJson("/api/stocktake/{$session->id}/entries", [
            'item_id'          => $item->id,
            'counted_quantity' => 80,
        ]);
        $entryId = $entryResp->json('id');

        // Close before approving — stops at pending_approval.
        $this->postJson("/api/stocktake/{$session->id}/close")
            ->assertStatus(200)->assertJsonPath('status', 'pending_approval');

        // Approve the individual variance entry.
        $this->postJson("/api/stocktake/{$session->id}/entries/{$entryId}/approve", [
            'reason' => 'Confirmed via physical walkthrough',
        ])->assertStatus(200);

        // Manager approves the session — transitions to 'approved'.
        $this->postJson("/api/stocktake/{$session->id}/approve")
            ->assertStatus(200)->assertJsonPath('status', 'approved');

        // Final close applies the adjustments and transitions to 'closed'.
        $this->postJson("/api/stocktake/{$session->id}/close")
            ->assertStatus(200)->assertJsonPath('status', 'closed');
    }

    public function test_session_goes_to_pending_approval_when_variance_entries_exist(): void
    {
        $clerk     = $this->actingAsInventoryClerk();
        $item      = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $clerk->facility_id]);

        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => 100,
            'reserved'             => 0,
            'available_to_promise' => 100,
            'avg_daily_usage'      => 0,
        ]);

        $session = StocktakeSession::create([
            'storeroom_id' => $storeroom->id,
            'status'       => 'open',
            'started_by'   => $clerk->id,
            'started_at'   => now(),
        ]);

        $this->postJson("/api/stocktake/{$session->id}/entries", [
            'item_id'          => $item->id,
            'counted_quantity' => 90,  // 10% variance
        ]);

        $response = $this->postJson("/api/stocktake/{$session->id}/close");
        $response->assertStatus(200)->assertJsonPath('status', 'pending_approval');
    }
}
