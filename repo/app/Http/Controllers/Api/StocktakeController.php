<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StocktakeEntry;
use App\Models\StocktakeSession;
use App\Models\Storeroom;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StocktakeController extends Controller
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StocktakeSession::class);

        $user = $request->user();
        $query = StocktakeSession::with(['storeroom'])
            ->when($request->filled('storeroom_id'), fn($q) => $q->where('storeroom_id', $request->storeroom_id))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->orderByDesc('started_at');

        // Tenant isolation: non-admin sees only sessions whose storeroom
        // belongs to their facility. Enforced via a subquery so the filter
        // cannot be bypassed with ?storeroom_id=.
        if (!$user->isAdmin() && $user->facility_id !== null) {
            $query->whereIn('storeroom_id', Storeroom::where('facility_id', $user->facility_id)->pluck('id'));
        }

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'storeroom_id' => 'required|exists:storerooms,id',
        ]);

        $storeroom = Storeroom::findOrFail($data['storeroom_id']);

        // Policy checks role AND that the storeroom lives in the caller's facility.
        $this->authorize('start', [StocktakeSession::class, $storeroom]);

        $session = $this->inventoryService->startStocktake(
            $storeroom,
            $request->user()->id,
        );

        return response()->json($session, 201);
    }

    public function show(StocktakeSession $stocktakeSession): JsonResponse
    {
        $this->authorize('view', $stocktakeSession);

        $stocktakeSession->load(['storeroom', 'entries.item']);
        return response()->json($stocktakeSession);
    }

    public function addEntry(Request $request, StocktakeSession $stocktakeSession): JsonResponse
    {
        $this->authorize('addEntry', $stocktakeSession);

        $data = $request->validate([
            'item_id'          => 'required|exists:inventory_items,id',
            'counted_quantity' => 'required|numeric|min:0',
        ]);

        $entry = $this->inventoryService->recordStocktakeEntry(
            $stocktakeSession,
            InventoryItem::findOrFail($data['item_id']),
            (float) $data['counted_quantity'],
        );

        return response()->json($entry);
    }

    public function approveEntry(Request $request, StocktakeSession $stocktakeSession, StocktakeEntry $entry): JsonResponse
    {
        $this->authorize('approve', $stocktakeSession);

        if ($entry->session_id !== $stocktakeSession->id) {
            abort(404, 'Entry not found in this session.');
        }

        $data = $request->validate(['reason' => 'required|string|min:10']);

        $entry = $this->inventoryService->approveStocktakeEntry($entry, $request->user()->id, $data['reason']);

        return response()->json($entry);
    }

    public function close(Request $request, StocktakeSession $stocktakeSession): JsonResponse
    {
        $this->authorize('close', $stocktakeSession);

        $session = $this->inventoryService->closeStocktake($stocktakeSession, $request->user()->id);
        return response()->json($session->load('entries.item'));
    }

    public function approve(Request $request, StocktakeSession $stocktakeSession): JsonResponse
    {
        $this->authorize('approve', $stocktakeSession);

        if ($stocktakeSession->hasPendingApprovals()) {
            return response()->json(['message' => 'All variance entries must be individually approved first.'], 422);
        }

        $session = $this->inventoryService->applyStocktake($stocktakeSession, $request->user()->id);
        return response()->json($session);
    }
}
