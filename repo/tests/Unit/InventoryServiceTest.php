<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\ServiceOrder;
use App\Models\StockLedger;
use App\Models\StockLevel;
use App\Models\Storeroom;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unit-level behavior guarantees for InventoryService.
 *
 * These tests exercise the service directly rather than via HTTP, locking
 * down business-critical guarantees (ledger immutability semantics, ATP
 * invariant, reservation boundary, transfer symmetry) that could drift
 * if a controller change masks a service defect.
 */
class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InventoryService::class);
    }

    public function test_receive_creates_inbound_ledger_and_raises_on_hand(): void
    {
        $item      = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create();
        $user      = User::factory()->create();

        $entry = $this->service->receive($item, $storeroom, 15.0, $user->id);

        $this->assertEquals('inbound', $entry->transaction_type);
        $this->assertEquals(15.0, (float) $entry->quantity);
        $this->assertEquals(15.0, (float) $entry->balance_after);

        $level = StockLevel::where('item_id', $item->id)
            ->where('storeroom_id', $storeroom->id)
            ->first();
        $this->assertEquals(15.0, (float) $level->on_hand);
    }

    public function test_receive_rejects_zero_or_negative_quantity(): void
    {
        $this->expectException(ValidationException::class);

        $item      = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create();
        $user      = User::factory()->create();

        $this->service->receive($item, $storeroom, 0, $user->id);
    }

    public function test_issue_deducts_on_hand_and_creates_outbound_ledger(): void
    {
        $item      = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create();
        $user      = User::factory()->create();
        $this->service->receive($item, $storeroom, 50.0, $user->id);

        $entry = $this->service->issue($item, $storeroom, 20.0, $user->id);

        $this->assertEquals('outbound', $entry->transaction_type);
        $this->assertEquals(20.0, (float) $entry->quantity);

        $level = StockLevel::where('item_id', $item->id)
            ->where('storeroom_id', $storeroom->id)->first();
        $this->assertEquals(30.0, (float) $level->on_hand);
    }

    public function test_issue_throws_when_atp_is_insufficient(): void
    {
        $this->expectException(ValidationException::class);

        $item      = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create();
        $user      = User::factory()->create();
        $this->service->receive($item, $storeroom, 5.0, $user->id);

        $this->service->issue($item, $storeroom, 10.0, $user->id);
    }

    public function test_transfer_moves_stock_between_storerooms(): void
    {
        $item  = InventoryItem::factory()->create();
        $from  = Storeroom::factory()->create();
        $to    = Storeroom::factory()->create();
        $user  = User::factory()->create();
        $this->service->receive($item, $from, 100.0, $user->id);

        [$out, $in] = $this->service->transfer($item, $from, $to, 40.0, $user->id);

        $this->assertEquals('transfer', $out->transaction_type);
        $this->assertEquals('transfer', $in->transaction_type);

        $fromLevel = StockLevel::where('item_id', $item->id)->where('storeroom_id', $from->id)->first();
        $toLevel   = StockLevel::where('item_id', $item->id)->where('storeroom_id', $to->id)->first();
        $this->assertEquals(60.0, (float) $fromLevel->on_hand);
        $this->assertEquals(40.0, (float) $toLevel->on_hand);
    }

    public function test_transfer_rejects_insufficient_source_stock(): void
    {
        $this->expectException(ValidationException::class);

        $item = InventoryItem::factory()->create();
        $from = Storeroom::factory()->create();
        $to   = Storeroom::factory()->create();
        $user = User::factory()->create();
        $this->service->receive($item, $from, 5.0, $user->id);

        $this->service->transfer($item, $from, $to, 100.0, $user->id);
    }

    public function test_reserve_for_order_rejects_cross_facility_storeroom(): void
    {
        $this->expectException(ValidationException::class);

        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();
        $item      = InventoryItem::factory()->create();
        $storeroomB = Storeroom::factory()->create(['facility_id' => $facilityB->id]);
        $this->service->receive($item, $storeroomB, 50.0, 1);

        $order = ServiceOrder::factory()->create(['facility_id' => $facilityA->id]);

        $this->service->reserveForOrder($order, $item, $storeroomB, 5.0);
    }

    public function test_reserve_for_order_updates_reserved_and_atp(): void
    {
        $facility  = Facility::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $facility->id]);
        $item      = InventoryItem::factory()->create();
        $this->service->receive($item, $storeroom, 50.0, 1);

        $order = ServiceOrder::factory()->create(['facility_id' => $facility->id]);

        $reservation = $this->service->reserveForOrder($order, $item, $storeroom, 12.0);

        $this->assertEquals(12.0, (float) $reservation->quantity_reserved);
        $this->assertEquals('reserved', $reservation->status);

        $level = StockLevel::where('item_id', $item->id)->where('storeroom_id', $storeroom->id)->first();
        $this->assertEquals(12.0, (float) $level->reserved);
        $this->assertEquals(38.0, (float) $level->available_to_promise);
    }

    public function test_reserve_for_order_fails_when_atp_is_exceeded(): void
    {
        $this->expectException(ValidationException::class);

        $facility  = Facility::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $facility->id]);
        $item      = InventoryItem::factory()->create();
        $this->service->receive($item, $storeroom, 5.0, 1);

        $order = ServiceOrder::factory()->create(['facility_id' => $facility->id]);

        $this->service->reserveForOrder($order, $item, $storeroom, 10.0);
    }

    public function test_close_order_reservations_deducts_on_hand_under_deduct_at_close(): void
    {
        $facility  = Facility::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $facility->id]);
        $item      = InventoryItem::factory()->create();
        $this->service->receive($item, $storeroom, 30.0, 1);

        $order = ServiceOrder::factory()->create([
            'facility_id'          => $facility->id,
            'reservation_strategy' => 'deduct_at_close',
        ]);
        $this->service->recordForClose($order, $item, $storeroom, 7.0);

        $this->service->closeOrderReservations($order);

        $level = StockLevel::where('item_id', $item->id)->where('storeroom_id', $storeroom->id)->first();
        $this->assertEquals(23.0, (float) $level->on_hand);
        $this->assertEquals(0.0, (float) $level->reserved);
    }

    public function test_ledger_entries_are_immutable_after_creation(): void
    {
        $item      = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create();
        $user      = User::factory()->create();
        $entry     = $this->service->receive($item, $storeroom, 10.0, $user->id);
        $originalQuantity = (float) $entry->quantity;

        // The ledger is append-only — the `updating` and `deleting` model
        // events return false so mutations silently fail rather than persist.
        $this->assertFalse($entry->update(['quantity' => 9999]));
        $this->assertFalse($entry->delete());

        $fresh = StockLedger::find($entry->id);
        $this->assertNotNull($fresh);
        $this->assertEquals($originalQuantity, (float) $fresh->quantity);
    }

    public function test_low_stock_alerts_scope_to_given_facility(): void
    {
        $a = Facility::factory()->create();
        $b = Facility::factory()->create();
        $storeroomA = Storeroom::factory()->create(['facility_id' => $a->id]);
        $storeroomB = Storeroom::factory()->create(['facility_id' => $b->id]);

        $item = InventoryItem::factory()->create(['safety_stock_days' => 30]);
        $this->service->receive($item, $storeroomA, 0.5, 1);
        $this->service->receive($item, $storeroomB, 0.5, 1);

        // Force a non-zero avg_daily_usage so the safety-stock math flags both.
        StockLevel::query()->update(['avg_daily_usage' => 5]);

        $alerts = $this->service->getLowStockAlerts($a->id);
        foreach ($alerts as $alert) {
            $this->assertEquals($a->id, $alert->storeroom->facility_id);
        }
    }
}
