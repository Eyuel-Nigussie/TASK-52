<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\StockLevel;
use App\Models\StocktakeEntry;
use App\Models\StocktakeSession;
use App\Models\Storeroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies object-level (ownership) authorization: protected actions must
 * refuse to operate on entities that don't belong to the parent resource
 * in the URL, regardless of role. This guards against insecure direct
 * object reference (IDOR) style bugs.
 */
class ObjectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_approve_entry_from_different_session(): void
    {
        $manager = $this->actingAsManager();
        $item = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create(['facility_id' => $manager->facility_id]);

        StockLevel::create([
            'item_id'              => $item->id,
            'storeroom_id'         => $storeroom->id,
            'on_hand'              => 100,
            'reserved'             => 0,
            'available_to_promise' => 100,
            'avg_daily_usage'      => 0,
        ]);

        // Session A with an entry needing approval (20% variance)
        $sessionA = StocktakeSession::create([
            'storeroom_id' => $storeroom->id,
            'status'       => 'open',
            'started_by'   => $manager->id,
            'started_at'   => now(),
        ]);
        $entryResp = $this->postJson("/api/stocktake/{$sessionA->id}/entries", [
            'item_id'          => $item->id,
            'counted_quantity' => 80,
        ]);
        $entryId = $entryResp->json('id');

        // Session B is a totally separate session.
        $sessionB = StocktakeSession::create([
            'storeroom_id' => $storeroom->id,
            'status'       => 'open',
            'started_by'   => $manager->id,
            'started_at'   => now(),
        ]);

        // Attempt to approve Session A's entry via Session B's URL — must fail.
        $response = $this->postJson("/api/stocktake/{$sessionB->id}/entries/{$entryId}/approve", [
            'reason' => 'Trying to approve using the wrong session id in the URL.',
        ]);

        $response->assertStatus(404);
        // The entry must still be unapproved.
        $this->assertDatabaseHas('stocktake_entries', ['id' => $entryId, 'approved_by' => null]);
    }

    public function test_unauthenticated_cannot_access_any_protected_endpoint(): void
    {
        $routes = [
            ['GET',  '/api/facilities'],
            ['GET',  '/api/users'],
            ['GET',  '/api/inventory/items'],
            ['GET',  '/api/stocktake'],
            ['GET',  '/api/content'],
            ['GET',  '/api/reviews'],
            ['GET',  '/api/audit-logs'],
            ['GET',  '/api/merge-requests'],
        ];

        foreach ($routes as [$method, $uri]) {
            $response = $this->json($method, $uri);
            $this->assertEquals(401, $response->status(), "Expected 401 for {$method} {$uri}");
        }
    }

    public function test_role_guard_rejects_unprivileged_writes(): void
    {
        // Clerks must not create facilities, delete doctors, or access audit logs.
        $this->actingAsInventoryClerk();

        $this->postJson('/api/facilities', [
            'external_key' => 'FAC-CLK-001',
            'name'         => 'x',
            'address'      => 'x',
            'city'         => 'x',
            'state'        => 'IL',
            'zip'          => '00000',
        ])->assertStatus(403);

        $this->getJson('/api/audit-logs')->assertStatus(403);
        $this->getJson('/api/users')->assertStatus(403);
        $this->getJson('/api/merge-requests')->assertStatus(403);
    }
}
