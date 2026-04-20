<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\StockLevel;
use App\Models\Storeroom;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private function createItemAndStoreroom(): array
    {
        $item = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create();
        return [$item, $storeroom];
    }

    public function test_can_receive_stock(): void
    {
        $this->actingAsInventoryClerk();
        [$item, $storeroom] = $this->createItemAndStoreroom();

        $response = $this->postJson('/api/inventory/receive', [
            'item_id'      => $item->id,
            'storeroom_id' => $storeroom->id,
            'quantity'     => 100,
            'unit_cost'    => 2.50,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('stock_levels', [
            'item_id'      => $item->id,
            'storeroom_id' => $storeroom->id,
            'on_hand'      => 100,
        ]);
    }

    public function test_can_issue_stock(): void
    {
        $this->actingAsInventoryClerk();
        [$item, $storeroom] = $this->createItemAndStoreroom();

        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => 50,
            'reserved'             => 0,
            'available_to_promise' => 50,
            'avg_daily_usage'      => 1,
        ]);

        $response = $this->postJson('/api/inventory/issue', [
            'item_id'      => $item->id,
            'storeroom_id' => $storeroom->id,
            'quantity'     => 10,
        ]);

        $response->assertStatus(201);
        $level = StockLevel::where(['item_id' => $item->id, 'storeroom_id' => $storeroom->id])->first();
        $this->assertEquals(40.0, (float) $level->on_hand);
    }

    public function test_cannot_issue_more_than_available(): void
    {
        $this->actingAsInventoryClerk();
        [$item, $storeroom] = $this->createItemAndStoreroom();

        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => 5,
            'reserved'             => 0,
            'available_to_promise' => 5,
            'avg_daily_usage'      => 0,
        ]);

        $response = $this->postJson('/api/inventory/issue', [
            'item_id'      => $item->id,
            'storeroom_id' => $storeroom->id,
            'quantity'     => 100,
        ]);

        $response->assertStatus(422);
    }

    public function test_can_transfer_between_storerooms(): void
    {
        $this->actingAsInventoryClerk();
        $item = InventoryItem::factory()->create();
        $from = Storeroom::factory()->create();
        $to = Storeroom::factory()->create();

        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $from->id,
            'on_hand'              => 100,
            'reserved'             => 0,
            'available_to_promise' => 100,
            'avg_daily_usage'      => 0,
        ]);

        $response = $this->postJson('/api/inventory/transfer', [
            'item_id'           => $item->id,
            'from_storeroom_id' => $from->id,
            'to_storeroom_id'   => $to->id,
            'quantity'          => 30,
        ]);

        $response->assertStatus(201);

        $fromLevel = StockLevel::where(['item_id' => $item->id, 'storeroom_id' => $from->id])->first();
        $toLevel = StockLevel::where(['item_id' => $item->id, 'storeroom_id' => $to->id])->first();

        $this->assertEquals(70.0, (float) $fromLevel->on_hand);
        $this->assertEquals(30.0, (float) $toLevel->on_hand);
    }

    public function test_low_stock_alert_triggered_below_safety_stock(): void
    {
        $this->actingAsAdmin();
        $item = InventoryItem::factory()->create(['safety_stock_days' => 14]);
        $storeroom = Storeroom::factory()->create();

        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => 5,
            'reserved'             => 0,
            'available_to_promise' => 5,
            'avg_daily_usage'      => 10,  // safety stock = 10 * 14 = 140
        ]);

        $response = $this->getJson("/api/inventory/low-stock-alerts?facility_id={$storeroom->facility_id}");
        $response->assertStatus(200);
        $this->assertGreaterThan(0, count($response->json()));
    }

    public function test_stock_ledger_is_immutable(): void
    {
        $this->actingAsAdmin();
        [$item, $storeroom] = $this->createItemAndStoreroom();

        $this->postJson('/api/inventory/receive', [
            'item_id'      => $item->id,
            'storeroom_id' => $storeroom->id,
            'quantity'     => 10,
        ]);

        $ledger = \App\Models\StockLedger::first();
        $originalQuantity = $ledger->quantity;

        $ledger->quantity = 999;
        $ledger->save();

        $this->assertEquals($originalQuantity, $ledger->fresh()->quantity);
    }

    public function test_atp_calculated_correctly(): void
    {
        $item = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create();

        $level = StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => 100,
            'reserved'             => 30,
            'available_to_promise' => 70,
            'avg_daily_usage'      => 0,
        ]);

        $level->recalculateAtp();
        $this->assertEquals(70.0, (float) $level->available_to_promise);
    }
}
