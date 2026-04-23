<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\OrderInventoryReservation;
use App\Models\ServiceOrder;
use App\Models\StockLedger;
use App\Models\StockLevel;
use App\Models\StocktakeEntry;
use App\Models\StocktakeSession;
use App\Models\Storeroom;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(private readonly AuditService $audit) {}

    public function receive(
        InventoryItem $item,
        Storeroom $storeroom,
        float $quantity,
        int $performedBy,
        ?float $unitCost = null,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): StockLedger {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Quantity must be positive.']]);
        }

        return DB::transaction(function () use (
            $item, $storeroom, $quantity, $performedBy, $unitCost, $notes, $referenceType, $referenceId
        ) {
            $level = $this->getOrCreateLevel($item->id, $storeroom->id);
            $balanceAfter = (float) $level->on_hand + $quantity;

            $entry = StockLedger::create([
                'item_id'          => $item->id,
                'storeroom_id'     => $storeroom->id,
                'transaction_type' => 'inbound',
                'quantity'         => $quantity,
                'balance_after'    => $balanceAfter,
                'reference_type'   => $referenceType,
                'reference_id'     => $referenceId,
                'unit_cost'        => $unitCost,
                'notes'            => $notes,
                'performed_by'     => $performedBy,
            ]);

            $level->increment('on_hand', $quantity);
            $level->recalculateAtp();
            $this->updateAvgDailyUsage($item->id, $storeroom->id);
            $this->audit->log('inventory.receive', InventoryItem::class, $item->id, null, ['quantity' => $quantity]);

            return $entry;
        });
    }

    public function issue(
        InventoryItem $item,
        Storeroom $storeroom,
        float $quantity,
        int $performedBy,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null
    ): StockLedger {
        return DB::transaction(function () use (
            $item, $storeroom, $quantity, $performedBy, $referenceType, $referenceId, $notes
        ) {
            $level = $this->getOrCreateLevel($item->id, $storeroom->id);

            if ((float) $level->available_to_promise < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['Insufficient available-to-promise quantity.'],
                ]);
            }

            $balanceAfter = (float) $level->on_hand - $quantity;

            $entry = StockLedger::create([
                'item_id'          => $item->id,
                'storeroom_id'     => $storeroom->id,
                'transaction_type' => 'outbound',
                'quantity'         => $quantity,
                'balance_after'    => $balanceAfter,
                'reference_type'   => $referenceType,
                'reference_id'     => $referenceId,
                'notes'            => $notes,
                'performed_by'     => $performedBy,
            ]);

            $level->decrement('on_hand', $quantity);
            $level->recalculateAtp();
            $this->updateAvgDailyUsage($item->id, $storeroom->id);
            $this->audit->log('inventory.issue', InventoryItem::class, $item->id, null, ['quantity' => $quantity]);

            return $entry;
        });
    }

    public function transfer(
        InventoryItem $item,
        Storeroom $fromStoreroom,
        Storeroom $toStoreroom,
        float $quantity,
        int $performedBy,
        ?string $notes = null
    ): array {
        return DB::transaction(function () use (
            $item, $fromStoreroom, $toStoreroom, $quantity, $performedBy, $notes
        ) {
            $fromLevel = $this->getOrCreateLevel($item->id, $fromStoreroom->id);

            if ((float) $fromLevel->available_to_promise < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['Insufficient stock in source storeroom.'],
                ]);
            }

            $fromBalanceAfter = (float) $fromLevel->on_hand - $quantity;
            $toLevel = $this->getOrCreateLevel($item->id, $toStoreroom->id);
            $toBalanceAfter = (float) $toLevel->on_hand + $quantity;

            $outEntry = StockLedger::create([
                'item_id'           => $item->id,
                'storeroom_id'      => $fromStoreroom->id,
                'transaction_type'  => 'transfer',
                'quantity'          => $quantity,
                'balance_after'     => $fromBalanceAfter,
                'from_storeroom_id' => $fromStoreroom->id,
                'to_storeroom_id'   => $toStoreroom->id,
                'notes'             => $notes,
                'performed_by'      => $performedBy,
            ]);

            $inEntry = StockLedger::create([
                'item_id'           => $item->id,
                'storeroom_id'      => $toStoreroom->id,
                'transaction_type'  => 'transfer',
                'quantity'          => $quantity,
                'balance_after'     => $toBalanceAfter,
                'from_storeroom_id' => $fromStoreroom->id,
                'to_storeroom_id'   => $toStoreroom->id,
                'notes'             => $notes,
                'performed_by'      => $performedBy,
            ]);

            $fromLevel->decrement('on_hand', $quantity);
            $fromLevel->recalculateAtp();
            $toLevel->increment('on_hand', $quantity);
            $toLevel->recalculateAtp();

            $this->audit->log('inventory.transfer', InventoryItem::class, $item->id, null, [
                'from' => $fromStoreroom->id, 'to' => $toStoreroom->id, 'quantity' => $quantity,
            ]);

            return [$outEntry, $inEntry];
        });
    }

    public function startStocktake(Storeroom $storeroom, int $startedBy): StocktakeSession
    {
        $existing = StocktakeSession::where('storeroom_id', $storeroom->id)
            ->whereIn('status', ['open', 'pending_approval'])
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'storeroom_id' => ['An active stocktake session already exists for this storeroom.'],
            ]);
        }

        return StocktakeSession::create([
            'storeroom_id' => $storeroom->id,
            'status'       => 'open',
            'started_by'   => $startedBy,
            'started_at'   => now(),
        ]);
    }

    public function recordStocktakeEntry(
        StocktakeSession $session,
        InventoryItem $item,
        float $countedQuantity
    ): StocktakeEntry {
        if ($session->status !== 'open') {
            throw ValidationException::withMessages([
                'session_id' => ['Stocktake session is not open.'],
            ]);
        }

        $level = $this->getOrCreateLevel($item->id, $session->storeroom_id);
        $systemQty = (float) $level->on_hand;
        $variancePct = StocktakeEntry::calculateVariancePct($systemQty, $countedQuantity);
        $requiresApproval = $variancePct > (float) config('vetops.stocktake_variance_pct', 5);

        return StocktakeEntry::updateOrCreate(
            ['session_id' => $session->id, 'item_id' => $item->id],
            [
                'system_quantity'  => $systemQty,
                'counted_quantity' => $countedQuantity,
                'variance_pct'     => $variancePct,
                'requires_approval' => $requiresApproval,
            ]
        );
    }

    public function closeStocktake(StocktakeSession $session, int $closedBy): StocktakeSession
    {
        if ($session->status === 'approved') {
            return $this->applyStocktake($session, $closedBy);
        }

        if ($session->hasPendingApprovals()) {
            $session->update(['status' => 'pending_approval']);
            return $session->refresh();
        }

        return $this->applyStocktake($session, $closedBy);
    }

    public function approveSession(StocktakeSession $session, int $approvedBy): StocktakeSession
    {
        $session->update([
            'status'      => 'approved',
            'approved_by' => $approvedBy,
        ]);
        return $session->refresh();
    }

    public function approveStocktakeEntry(
        StocktakeEntry $entry,
        int $approvedBy,
        string $reason
    ): StocktakeEntry {
        $entry->update([
            'approved_by'     => $approvedBy,
            'approval_reason' => $reason,
        ]);
        return $entry->refresh();
    }

    public function applyStocktake(StocktakeSession $session, int $appliedBy): StocktakeSession
    {
        return DB::transaction(function () use ($session, $appliedBy) {
            foreach ($session->entries as $entry) {
                $level = $this->getOrCreateLevel($entry->item_id, $session->storeroom_id);
                $diff = (float) $entry->counted_quantity - (float) $level->on_hand;

                if ($diff !== 0.0) {
                    StockLedger::create([
                        'item_id'          => $entry->item_id,
                        'storeroom_id'     => $session->storeroom_id,
                        'transaction_type' => 'stocktake',
                        'quantity'         => $diff,
                        'balance_after'    => (float) $entry->counted_quantity,
                        'reference_type'   => StocktakeSession::class,
                        'reference_id'     => $session->id,
                        'notes'            => "Stocktake adjustment",
                        'performed_by'     => $appliedBy,
                        'approved_by'      => $appliedBy,
                    ]);

                    $level->update(['on_hand' => $entry->counted_quantity, 'last_stocktake_at' => now()]);
                    $level->recalculateAtp();
                }
            }

            $session->update([
                'status'    => 'closed',
                'closed_at' => now(),
            ]);

            return $session->refresh();
        });
    }

    public function reserveForOrder(ServiceOrder $order, InventoryItem $item, Storeroom $storeroom, float $quantity): OrderInventoryReservation
    {
        // Tenant boundary: a service order may only draw from storerooms
        // belonging to its own facility. Crossing facilities here would
        // bypass the facility-scoped inventory ledger.
        if ((int) $storeroom->facility_id !== (int) $order->facility_id) {
            throw ValidationException::withMessages([
                'storeroom_id' => ['Storeroom belongs to a different facility than this service order.'],
            ]);
        }

        $level = $this->getOrCreateLevel($item->id, $storeroom->id);

        if ((float) $level->available_to_promise < $quantity) {
            throw ValidationException::withMessages([
                'quantity' => ['Insufficient available stock for reservation.'],
            ]);
        }

        $level->increment('reserved', $quantity);
        $level->recalculateAtp();

        return OrderInventoryReservation::create([
            'service_order_id'  => $order->id,
            'item_id'           => $item->id,
            'storeroom_id'      => $storeroom->id,
            'quantity_reserved' => $quantity,
            'status'            => 'reserved',
        ]);
    }

    public function closeOrderReservations(ServiceOrder $order): void
    {
        DB::transaction(function () use ($order) {
            foreach ($order->reservations()->where('status', 'reserved')->get() as $reservation) {
                $level = $this->getOrCreateLevel($reservation->item_id, $reservation->storeroom_id);

                // Both strategies consume on_hand at close: deduct_at_close was
                // optimistic (never touched on_hand at creation); lock_at_creation
                // locked ATP by incrementing reserved but never reduced on_hand —
                // the actual physical deduction happens here in both cases.
                $this->issue(
                    InventoryItem::find($reservation->item_id),
                    Storeroom::find($reservation->storeroom_id),
                    (float) $reservation->quantity_reserved,
                    $order->created_by ?? 0,
                    ServiceOrder::class,
                    $order->id,
                    "Auto-deduct on order close"
                );

                $level->decrement('reserved', (float) $reservation->quantity_reserved);
                $level->recalculateAtp();

                $reservation->update([
                    'quantity_deducted' => $reservation->quantity_reserved,
                    'status'            => 'deducted',
                ]);
            }
        });
    }

    public function getLowStockAlerts(int $facilityId): \Illuminate\Support\Collection
    {
        return StockLevel::with(['item', 'storeroom.facility'])
            ->whereHas('storeroom', fn($q) => $q->where('facility_id', $facilityId))
            ->get()
            ->filter(fn(StockLevel $level) => $level->isBelowSafetyStock());
    }

    private function getOrCreateLevel(int $itemId, int $storeroomId): StockLevel
    {
        return StockLevel::firstOrCreate(
            ['item_id' => $itemId, 'storeroom_id' => $storeroomId],
            ['on_hand' => 0, 'reserved' => 0, 'available_to_promise' => 0, 'avg_daily_usage' => 0]
        );
    }

    private function updateAvgDailyUsage(int $itemId, int $storeroomId): void
    {
        $days = 30;
        $outbound = StockLedger::where('item_id', $itemId)
            ->where('storeroom_id', $storeroomId)
            ->where('transaction_type', 'outbound')
            ->where('created_at', '>=', now()->subDays($days))
            ->sum(DB::raw('ABS(quantity)'));

        $avgDailyUsage = (float) $outbound / $days;
        StockLevel::where('item_id', $itemId)->where('storeroom_id', $storeroomId)
            ->update(['avg_daily_usage' => $avgDailyUsage]);
    }
}
