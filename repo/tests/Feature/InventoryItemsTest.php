<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\StockLedger;
use App\Models\StockLevel;
use App\Models\Storeroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_inventory_items(): void
    {
        $this->actingAsInventoryClerk();
        InventoryItem::factory()->count(4)->create();

        $response = $this->getJson('/api/inventory/items');

        $response->assertStatus(200)
            ->assertJsonPath('total', 4);
    }

    public function test_can_search_items_by_name(): void
    {
        $this->actingAsInventoryClerk();
        InventoryItem::factory()->create(['name' => 'Amoxicillin Tablets']);
        InventoryItem::factory()->create(['name' => 'Gauze Bandages']);

        $response = $this->getJson('/api/inventory/items?search=Amoxicillin');

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_can_filter_items_by_category(): void
    {
        $this->actingAsInventoryClerk();
        InventoryItem::factory()->create(['category' => 'surgical']);
        InventoryItem::factory()->create(['category' => 'surgical']);
        InventoryItem::factory()->create(['category' => 'pharmacy']);

        $response = $this->getJson('/api/inventory/items?category=surgical');

        $response->assertStatus(200)
            ->assertJsonPath('total', 2);
    }

    public function test_active_only_filter_excludes_inactive_items(): void
    {
        $this->actingAsInventoryClerk();
        InventoryItem::factory()->create(['active' => true]);
        InventoryItem::factory()->create(['active' => false]);

        $response = $this->getJson('/api/inventory/items?active_only=1');

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_clerk_can_create_inventory_item(): void
    {
        $this->actingAsInventoryClerk();

        $response = $this->postJson('/api/inventory/items', [
            'external_key'     => 'ITEM-UNIT-001',
            'name'             => 'Sterile Saline Bag',
            'sku'              => 'SKU-SALINE-001',
            'category'         => 'consumables',
            'unit_of_measure'  => 'bag',
            'safety_stock_days' => 14,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Sterile Saline Bag');
        $this->assertDatabaseHas('inventory_items', ['external_key' => 'ITEM-UNIT-001']);
    }

    public function test_technician_cannot_create_inventory_item(): void
    {
        $this->actingAsTechnicianDoctor();

        $response = $this->postJson('/api/inventory/items', [
            'external_key' => 'ITEM-DENY-001',
            'name'         => 'Denied Item',
            'category'     => 'pharmacy',
        ]);

        $response->assertStatus(403);
    }

    public function test_duplicate_external_key_rejected(): void
    {
        $this->actingAsInventoryClerk();
        InventoryItem::factory()->create(['external_key' => 'ITEM-DUP-001']);

        $response = $this->postJson('/api/inventory/items', [
            'external_key' => 'ITEM-DUP-001',
            'name'         => 'Duplicate Item',
            'category'     => 'pharmacy',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['external_key']);
    }

    public function test_clerk_can_update_inventory_item(): void
    {
        $this->actingAsInventoryClerk();
        $item = InventoryItem::factory()->create(['name' => 'Original Name']);

        $response = $this->putJson("/api/inventory/items/{$item->id}", [
            'name'              => 'Updated Name',
            'safety_stock_days' => 30,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Updated Name');
    }

    public function test_item_update_creates_audit_log(): void
    {
        $this->actingAsInventoryClerk();
        $item = InventoryItem::factory()->create();

        $this->putJson("/api/inventory/items/{$item->id}", ['name' => 'Audited Name']);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'inventory_item.update',
            'entity_type' => InventoryItem::class,
            'entity_id'   => $item->id,
        ]);
    }

    public function test_can_list_stock_levels(): void
    {
        $clerk = $this->actingAsInventoryClerk();
        $item = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $clerk->facility_id]);
        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => 50,
            'reserved'             => 0,
            'available_to_promise' => 50,
            'avg_daily_usage'      => 2,
        ]);

        $response = $this->getJson('/api/inventory/stock-levels');

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_can_filter_stock_levels_by_storeroom(): void
    {
        $clerk = $this->actingAsInventoryClerk();
        $item = InventoryItem::factory()->create();
        $s1 = Storeroom::factory()->create(['facility_id' => $clerk->facility_id]);
        $s2 = Storeroom::factory()->create(['facility_id' => $clerk->facility_id]);

        foreach ([$s1, $s2] as $sr) {
            StockLevel::create([
                'item_id'              => $item->id,
                'storeroom_id'         => $sr->id,
                'on_hand'              => 10,
                'reserved'             => 0,
                'available_to_promise' => 10,
                'avg_daily_usage'      => 0,
            ]);
        }

        $response = $this->getJson("/api/inventory/stock-levels?storeroom_id={$s1->id}");

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_ledger_filters_by_transaction_type(): void
    {
        $clerk = $this->actingAsInventoryClerk();
        $item = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $clerk->facility_id]);

        // Receive creates an 'inbound' ledger entry
        $this->postJson('/api/inventory/receive', [
            'item_id'      => $item->id,
            'storeroom_id' => $storeroom->id,
            'quantity'     => 20,
        ]);

        $response = $this->getJson('/api/inventory/ledger?transaction_type=inbound');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('total'));
    }

    public function test_transfer_requires_different_storerooms(): void
    {
        $this->actingAsInventoryClerk();
        $item = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create();

        $response = $this->postJson('/api/inventory/transfer', [
            'item_id'           => $item->id,
            'from_storeroom_id' => $storeroom->id,
            'to_storeroom_id'   => $storeroom->id,
            'quantity'          => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['to_storeroom_id']);
    }

    public function test_low_stock_requires_facility_id(): void
    {
        // Admin must always supply facility_id; non-admin uses their own facility.
        $this->actingAsAdmin();

        $response = $this->getJson('/api/inventory/low-stock-alerts');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['facility_id']);
    }
}
