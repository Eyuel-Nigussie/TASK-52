<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\StockLedger;
use App\Models\StockLevel;
use App\Models\StocktakeSession;
use App\Models\Storeroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inventory + stocktake cross-facility regression guard.
 * A clerk at facility A must not be able to receive/issue/transfer stock
 * against facility B's storerooms, nor peek at facility B's ledger/levels
 * or stocktake sessions.
 */
class InventoryIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsClerkOf(Facility $facility): User
    {
        $user = User::factory()->inventoryClerk()->create(['facility_id' => $facility->id]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    private function actingAsManagerOf(Facility $facility): User
    {
        $user = User::factory()->manager()->create(['facility_id' => $facility->id]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    public function test_clerk_cannot_receive_into_foreign_storeroom(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsClerkOf($a);

        $foreign = Storeroom::factory()->create(['facility_id' => $b->id]);
        $item = InventoryItem::factory()->create();

        $this->postJson('/api/inventory/receive', [
            'item_id'      => $item->id,
            'storeroom_id' => $foreign->id,
            'quantity'     => 10,
        ])->assertStatus(403);
    }

    public function test_clerk_cannot_issue_from_foreign_storeroom(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsClerkOf($a);

        $foreign = Storeroom::factory()->create(['facility_id' => $b->id]);
        $item = InventoryItem::factory()->create();
        StockLevel::create([
            'item_id' => $item->id, 'storeroom_id' => $foreign->id,
            'on_hand' => 100, 'reserved' => 0, 'available_to_promise' => 100, 'avg_daily_usage' => 0,
        ]);

        $this->postJson('/api/inventory/issue', [
            'item_id'      => $item->id,
            'storeroom_id' => $foreign->id,
            'quantity'     => 1,
        ])->assertStatus(403);
    }

    public function test_clerk_cannot_transfer_across_facilities(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsClerkOf($a);

        $mine = Storeroom::factory()->create(['facility_id' => $a->id]);
        $foreign = Storeroom::factory()->create(['facility_id' => $b->id]);
        $item = InventoryItem::factory()->create();

        $this->postJson('/api/inventory/transfer', [
            'item_id'           => $item->id,
            'from_storeroom_id' => $mine->id,
            'to_storeroom_id'   => $foreign->id,
            'quantity'          => 5,
        ])->assertStatus(403);
    }

    public function test_stock_levels_listing_is_scoped_to_caller_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsClerkOf($a);

        $mine = Storeroom::factory()->create(['facility_id' => $a->id]);
        $foreign = Storeroom::factory()->create(['facility_id' => $b->id]);
        $item = InventoryItem::factory()->create();

        StockLevel::create(['item_id' => $item->id, 'storeroom_id' => $mine->id, 'on_hand' => 10, 'reserved' => 0, 'available_to_promise' => 10, 'avg_daily_usage' => 0]);
        StockLevel::create(['item_id' => $item->id, 'storeroom_id' => $foreign->id, 'on_hand' => 20, 'reserved' => 0, 'available_to_promise' => 20, 'avg_daily_usage' => 0]);

        $response = $this->getJson('/api/inventory/stock-levels?storeroom_id=' . $foreign->id);
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_ledger_listing_is_scoped_to_caller_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $clerk = $this->actingAsClerkOf($a);

        $mine = Storeroom::factory()->create(['facility_id' => $a->id]);
        $foreign = Storeroom::factory()->create(['facility_id' => $b->id]);
        $item = InventoryItem::factory()->create();

        StockLedger::create(['item_id' => $item->id, 'storeroom_id' => $mine->id, 'transaction_type' => 'inbound', 'quantity' => 10, 'balance_after' => 10, 'performed_by' => $clerk->id]);
        StockLedger::create(['item_id' => $item->id, 'storeroom_id' => $foreign->id, 'transaction_type' => 'inbound', 'quantity' => 50, 'balance_after' => 50, 'performed_by' => $clerk->id]);

        $response = $this->getJson('/api/inventory/ledger');
        $response->assertStatus(200);
        foreach ($response->json('data') as $entry) {
            $this->assertEquals($mine->id, $entry['storeroom_id']);
        }
    }

    public function test_clerk_cannot_start_stocktake_in_foreign_storeroom(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsClerkOf($a);

        $foreign = Storeroom::factory()->create(['facility_id' => $b->id]);

        $this->postJson('/api/stocktake/start', ['storeroom_id' => $foreign->id])
            ->assertStatus(403);
    }

    public function test_manager_cannot_view_foreign_stocktake_session(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $this->actingAsManagerOf($a);

        $foreignRoom = Storeroom::factory()->create(['facility_id' => $b->id]);
        $foreignUser = User::factory()->manager()->create(['facility_id' => $b->id]);
        $foreignSession = StocktakeSession::create([
            'storeroom_id' => $foreignRoom->id,
            'status'       => 'open',
            'started_by'   => $foreignUser->id,
            'started_at'   => now(),
        ]);

        $this->getJson("/api/stocktake/{$foreignSession->id}")->assertStatus(403);
    }

    public function test_stocktake_list_is_scoped_to_caller_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $mgr = $this->actingAsManagerOf($a);

        $mineRoom = Storeroom::factory()->create(['facility_id' => $a->id]);
        $foreignRoom = Storeroom::factory()->create(['facility_id' => $b->id]);

        StocktakeSession::create(['storeroom_id' => $mineRoom->id, 'status' => 'open', 'started_by' => $mgr->id, 'started_at' => now()]);
        StocktakeSession::create(['storeroom_id' => $foreignRoom->id, 'status' => 'open', 'started_by' => $mgr->id, 'started_at' => now()]);

        $response = $this->getJson('/api/stocktake');
        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('storeroom_id');
        $this->assertTrue($ids->contains($mineRoom->id));
        $this->assertFalse($ids->contains($foreignRoom->id));
    }

    public function test_admin_bypasses_storeroom_facility_check(): void
    {
        $this->actingAsAdmin();
        $b = Facility::factory()->create();
        $foreign = Storeroom::factory()->create(['facility_id' => $b->id]);
        $item = InventoryItem::factory()->create();

        $this->postJson('/api/inventory/receive', [
            'item_id'      => $item->id,
            'storeroom_id' => $foreign->id,
            'quantity'     => 5,
        ])->assertStatus(201);
    }
}
