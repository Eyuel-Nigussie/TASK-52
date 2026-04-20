<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockLedger;
use App\Models\StockLevel;
use App\Models\Storeroom;
use App\Services\AuditService;
use App\Services\DataVersioningService;
use App\Services\InventoryService;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly AuditService $audit,
        private readonly ImportService $importService,
        private readonly DataVersioningService $versioning,
    ) {}

    public function items(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryItem::class);

        $items = InventoryItem::query()
            ->when($request->filled('search'), fn($q) => $q->where('name', 'like', '%' . $request->search . '%'))
            ->when($request->filled('category'), fn($q) => $q->where('category', $request->category))
            ->when($request->boolean('active_only'), fn($q) => $q->active())
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json($items);
    }

    public function createItem(Request $request): JsonResponse
    {
        $this->authorize('create', InventoryItem::class);

        $data = $request->validate([
            'external_key'     => 'required|string|max:100|unique:inventory_items',
            'name'             => 'required|string|max:255',
            'sku'              => 'nullable|string|max:100|unique:inventory_items',
            'category'         => 'required|string|max:100',
            'unit_of_measure'  => 'nullable|string|max:50',
            'safety_stock_days' => 'nullable|integer|min:1',
            'supplier_info'    => 'nullable|array',
        ]);

        $item = InventoryItem::create($data);
        $this->versioning->record($item, [], $request->user()->id, 'Created via API');
        $this->audit->logModel('inventory_item.create', $item);

        return response()->json($item, 201);
    }

    public function updateItem(Request $request, InventoryItem $item): JsonResponse
    {
        $this->authorize('update', $item);

        $data = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'category'         => 'sometimes|string|max:100',
            'unit_of_measure'  => 'nullable|string|max:50',
            'safety_stock_days' => 'nullable|integer|min:1',
            'supplier_info'    => 'nullable|array',
            'active'           => 'sometimes|boolean',
        ]);

        $old = $item->toArray();
        $item->update($data);
        $this->versioning->record($item, $old, $request->user()->id, 'Updated via API');
        $this->audit->logModel('inventory_item.update', $item, $old, $item->fresh()->toArray());

        return response()->json($item->fresh());
    }

    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id'       => 'required|exists:inventory_items,id',
            'storeroom_id'  => 'required|exists:storerooms,id',
            'quantity'      => 'required|numeric|min:0.001',
            'unit_cost'     => 'nullable|numeric|min:0',
            'notes'         => 'nullable|string',
            'reference_type' => 'nullable|string|max:100',
            'reference_id'   => 'nullable|integer',
        ]);

        $storeroom = Storeroom::findOrFail($data['storeroom_id']);
        $this->assertStoreroomAccessible($request, $storeroom);

        $entry = $this->inventoryService->receive(
            InventoryItem::findOrFail($data['item_id']),
            $storeroom,
            (float) $data['quantity'],
            $request->user()->id,
            isset($data['unit_cost']) ? (float) $data['unit_cost'] : null,
            $data['notes'] ?? null,
            $data['reference_type'] ?? null,
            isset($data['reference_id']) ? (int) $data['reference_id'] : null,
        );

        return response()->json($entry, 201);
    }

    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id'       => 'required|exists:inventory_items,id',
            'storeroom_id'  => 'required|exists:storerooms,id',
            'quantity'      => 'required|numeric|min:0.001',
            'notes'         => 'nullable|string',
            'reference_type' => 'nullable|string|max:100',
            'reference_id'   => 'nullable|integer',
        ]);

        $storeroom = Storeroom::findOrFail($data['storeroom_id']);
        $this->assertStoreroomAccessible($request, $storeroom);

        $entry = $this->inventoryService->issue(
            InventoryItem::findOrFail($data['item_id']),
            $storeroom,
            (float) $data['quantity'],
            $request->user()->id,
            $data['reference_type'] ?? null,
            isset($data['reference_id']) ? (int) $data['reference_id'] : null,
            $data['notes'] ?? null,
        );

        return response()->json($entry, 201);
    }

    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id'            => 'required|exists:inventory_items,id',
            'from_storeroom_id'  => 'required|exists:storerooms,id',
            'to_storeroom_id'    => 'required|exists:storerooms,id|different:from_storeroom_id',
            'quantity'           => 'required|numeric|min:0.001',
            'notes'              => 'nullable|string',
        ]);

        $from = Storeroom::findOrFail($data['from_storeroom_id']);
        $to   = Storeroom::findOrFail($data['to_storeroom_id']);

        // Both sides must belong to the caller's facility — transferring
        // between facilities would be a separate, accounted-for workflow.
        $this->assertStoreroomAccessible($request, $from);
        $this->assertStoreroomAccessible($request, $to);

        [$out, $in] = $this->inventoryService->transfer(
            InventoryItem::findOrFail($data['item_id']),
            $from,
            $to,
            (float) $data['quantity'],
            $request->user()->id,
            $data['notes'] ?? null,
        );

        return response()->json(['out' => $out, 'in' => $in], 201);
    }

    public function stockLevels(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryItem::class);

        $user = $request->user();
        $query = StockLevel::with(['item', 'storeroom'])
            ->when($request->filled('storeroom_id'), fn($q) => $q->where('storeroom_id', $request->storeroom_id))
            ->when($request->filled('item_id'), fn($q) => $q->where('item_id', $request->item_id));

        if (!$user->isAdmin() && $user->facility_id !== null) {
            $query->whereIn('storeroom_id', Storeroom::where('facility_id', $user->facility_id)->pluck('id'));
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    public function lowStockAlerts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryItem::class);

        $user = $request->user();

        // Non-admin users are locked to their own facility regardless of the param.
        if (!$user->isAdmin() && $user->facility_id !== null) {
            $facilityId = $user->facility_id;
        } else {
            $request->validate(['facility_id' => 'required|exists:facilities,id']);
            $facilityId = (int) $request->facility_id;
        }

        $alerts = $this->inventoryService->getLowStockAlerts($facilityId);
        return response()->json($alerts->values());
    }

    public function ledger(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryItem::class);

        $user = $request->user();
        $query = StockLedger::query()
            ->with(['item', 'storeroom', 'performer'])
            ->when($request->filled('item_id'), fn($q) => $q->where('item_id', $request->item_id))
            ->when($request->filled('storeroom_id'), fn($q) => $q->where('storeroom_id', $request->storeroom_id))
            ->when($request->filled('transaction_type'), fn($q) => $q->where('transaction_type', $request->transaction_type))
            ->orderByDesc('created_at');

        if (!$user->isAdmin() && $user->facility_id !== null) {
            $query->whereIn('storeroom_id', Storeroom::where('facility_id', $user->facility_id)->pluck('id'));
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    public function importItems(Request $request): JsonResponse
    {
        $this->authorize('import', InventoryItem::class);

        $request->validate(['file' => 'required|file|mimes:csv,txt|max:' . (config('vetops.upload_max_mb') * 1024)]);
        $import = $this->importService->queueImport($request->file('file'), 'inventory_item', $request->user()->id);
        $import = $this->importService->process($import);
        return response()->json($import);
    }

    public function exportItems(Request $request): StreamedResponse
    {
        $this->authorize('export', InventoryItem::class);

        $this->audit->logExport('inventory_item');
        $csv = $this->importService->export('inventory_item');
        return new StreamedResponse(function () use ($csv) {
            echo $csv;
        }, 200, ['Content-Type' => 'text/csv']);
    }

    /**
     * Tenant-isolation guard for storeroom-bound writes.
     * system_admin is excluded from the check; everyone else must match.
     */
    private function assertStoreroomAccessible(Request $request, Storeroom $storeroom): void
    {
        $user = $request->user();
        if ($user->isAdmin() || $user->facility_id === null) {
            return;
        }
        if ($storeroom->facility_id !== $user->facility_id) {
            abort(403, 'Storeroom belongs to another facility.');
        }
    }
}
