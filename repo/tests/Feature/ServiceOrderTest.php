<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\Patient;
use App\Models\ServiceOrder;
use App\Models\StockLevel;
use App\Models\Storeroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOrderTest extends TestCase
{
    use RefreshDatabase;

    private function setupStockLevel(float $onHand = 100.0, ?int $facilityId = null): array
    {
        $item      = InventoryItem::factory()->create();
        $storeroom = $facilityId !== null
            ? Storeroom::factory()->create(['facility_id' => $facilityId])
            : Storeroom::factory()->create();
        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => $onHand,
            'reserved'             => 0,
            'available_to_promise' => $onHand,
            'avg_daily_usage'      => 0,
        ]);
        return [$item, $storeroom];
    }

    public function test_can_list_service_orders(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        ServiceOrder::factory()->count(3)->create(['facility_id' => $tech->facility_id]);

        $response = $this->getJson('/api/service-orders');

        $response->assertStatus(200)
            ->assertJsonPath('total', 3);
    }

    public function test_can_create_simple_service_order(): void
    {
        $tech = $this->actingAsTechnicianDoctor();

        $response = $this->postJson('/api/service-orders', [
            'facility_id'          => $tech->facility_id,
            'reservation_strategy' => 'deduct_at_close',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('reservation_strategy', 'deduct_at_close');
    }

    public function test_can_create_order_with_lock_at_creation_strategy(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        [$item, $storeroom] = $this->setupStockLevel(50.0, $tech->facility_id);

        $response = $this->postJson('/api/service-orders', [
            'facility_id'          => $tech->facility_id,
            'reservation_strategy' => 'lock_at_creation',
            'items'                => [
                [
                    'item_id'      => $item->id,
                    'storeroom_id' => $storeroom->id,
                    'quantity'     => 10,
                ],
            ],
        ]);

        $response->assertStatus(201);

        $level = StockLevel::where('item_id', $item->id)->first();
        $this->assertEquals(10.0, (float) $level->reserved);
        $this->assertEquals(40.0, (float) $level->available_to_promise);
    }

    public function test_reservation_fails_when_insufficient_stock(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        [$item, $storeroom] = $this->setupStockLevel(5.0, $tech->facility_id);

        $response = $this->postJson('/api/service-orders', [
            'facility_id'          => $tech->facility_id,
            'reservation_strategy' => 'lock_at_creation',
            'items'                => [
                [
                    'item_id'      => $item->id,
                    'storeroom_id' => $storeroom->id,
                    'quantity'     => 100,
                ],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_can_show_service_order_with_reservations(): void
    {
        $tech  = $this->actingAsTechnicianDoctor();
        $order = ServiceOrder::factory()->create(['facility_id' => $tech->facility_id]);

        $response = $this->getJson("/api/service-orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $order->id);
        $this->assertArrayHasKey('reservations', $response->json());
    }

    public function test_technician_can_close_service_order(): void
    {
        $tech  = $this->actingAsTechnicianDoctor();
        $order = ServiceOrder::factory()->create([
            'facility_id'          => $tech->facility_id,
            'status'               => 'open',
            'reservation_strategy' => 'deduct_at_close',
        ]);

        $response = $this->postJson("/api/service-orders/{$order->id}/close");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'closed');
    }

    public function test_cannot_close_already_closed_order(): void
    {
        $tech  = $this->actingAsTechnicianDoctor();
        $order = ServiceOrder::factory()->create([
            'facility_id' => $tech->facility_id,
            'status'      => 'closed',
        ]);

        $response = $this->postJson("/api/service-orders/{$order->id}/close");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Order is not open.');
    }

    public function test_can_add_reservation_to_open_order(): void
    {
        $tech  = $this->actingAsTechnicianDoctor();
        $order = ServiceOrder::factory()->create([
            'facility_id'          => $tech->facility_id,
            'status'               => 'open',
            'reservation_strategy' => 'lock_at_creation',
        ]);
        [$item, $storeroom] = $this->setupStockLevel(30.0, $tech->facility_id);

        $response = $this->postJson("/api/service-orders/{$order->id}/reservations", [
            'item_id'      => $item->id,
            'storeroom_id' => $storeroom->id,
            'quantity'     => 5,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('order_inventory_reservations', [
            'service_order_id' => $order->id,
            'item_id'          => $item->id,
        ]);
    }

    public function test_filter_orders_by_facility(): void
    {
        $tech      = $this->actingAsTechnicianDoctor();
        $facilityB = Facility::factory()->create();
        ServiceOrder::factory()->count(2)->create(['facility_id' => $tech->facility_id]);
        ServiceOrder::factory()->count(3)->create(['facility_id' => $facilityB->id]);

        $response = $this->getJson("/api/service-orders?facility_id={$tech->facility_id}");

        $response->assertStatus(200)
            ->assertJsonPath('total', 2);
    }

    public function test_cannot_reserve_from_storeroom_of_another_facility_on_create(): void
    {
        $tech      = $this->actingAsTechnicianDoctor();
        $facilityB = Facility::factory()->create();
        [$item, $storeroomB] = $this->setupStockLevel(50.0, $facilityB->id);

        $response = $this->postJson('/api/service-orders', [
            'facility_id'          => $tech->facility_id,
            'reservation_strategy' => 'lock_at_creation',
            'items'                => [
                [
                    'item_id'      => $item->id,
                    'storeroom_id' => $storeroomB->id,
                    'quantity'     => 5,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['storeroom_id']);
    }

    public function test_cannot_add_reservation_from_storeroom_of_another_facility(): void
    {
        $tech      = $this->actingAsTechnicianDoctor();
        $facilityB = Facility::factory()->create();
        $order     = ServiceOrder::factory()->create([
            'facility_id'          => $tech->facility_id,
            'status'               => 'open',
            'reservation_strategy' => 'lock_at_creation',
        ]);
        [$item, $storeroomB] = $this->setupStockLevel(50.0, $facilityB->id);

        $response = $this->postJson("/api/service-orders/{$order->id}/reservations", [
            'item_id'      => $item->id,
            'storeroom_id' => $storeroomB->id,
            'quantity'     => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['storeroom_id']);
    }

    public function test_filter_orders_by_status(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        ServiceOrder::factory()->count(2)->create(['facility_id' => $tech->facility_id, 'status' => 'open']);
        ServiceOrder::factory()->count(1)->create(['facility_id' => $tech->facility_id, 'status' => 'closed']);

        $response = $this->getJson('/api/service-orders?status=open');

        $response->assertStatus(200)
            ->assertJsonPath('total', 2);
    }

    public function test_lock_at_creation_deducts_on_hand_when_order_is_closed(): void
    {
        $tech = $this->actingAsTechnicianDoctor();
        [$item, $storeroom] = $this->setupStockLevel(50.0, $tech->facility_id);

        // Create order — stock is reserved but on_hand is not yet touched.
        $createResp = $this->postJson('/api/service-orders', [
            'facility_id'          => $tech->facility_id,
            'reservation_strategy' => 'lock_at_creation',
            'items'                => [
                [
                    'item_id'      => $item->id,
                    'storeroom_id' => $storeroom->id,
                    'quantity'     => 10,
                ],
            ],
        ]);
        $createResp->assertStatus(201);
        $orderId = $createResp->json('id');

        $level = StockLevel::where('item_id', $item->id)->first();
        $this->assertEquals(50.0, (float) $level->on_hand, 'on_hand must not change at reservation time');
        $this->assertEquals(10.0, (float) $level->reserved);

        // Close the order — on_hand must be reduced.
        $this->postJson("/api/service-orders/{$orderId}/close")->assertStatus(200);

        $level->refresh();
        $this->assertEquals(40.0, (float) $level->on_hand, 'on_hand must be deducted on close');
        $this->assertEquals(0.0, (float) $level->reserved, 'reserved must be released on close');
    }
}
