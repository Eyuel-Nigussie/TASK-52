<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByFacility;
use App\Http\Controllers\Controller;
use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use App\Services\AuditService;
use App\Services\RentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RentalTransactionController extends Controller
{
    use ScopesByFacility;

    public function __construct(
        private readonly RentalService $rentalService,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RentalTransaction::class);

        $query = RentalTransaction::query()
            ->with(['asset', 'facility'])
            ->when($request->filled('asset_id'), fn($q) => $q->where('asset_id', $request->asset_id))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->boolean('overdue_only'), function ($q) {
                $q->whereIn('status', ['active', 'overdue'])
                  ->where('expected_return_at', '<', now()->subHours((int) config('vetops.overdue_hours', 2)));
            })
            ->orderByDesc('checked_out_at');

        $this->applyFacilityScope($query, $request->user(), $request->integer('facility_id') ?: null);

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function checkout(Request $request): JsonResponse
    {
        $this->authorize('checkout', RentalTransaction::class);

        $data = $request->validate([
            'asset_id'           => 'required|exists:rental_assets,id',
            'renter_type'        => 'required|in:department,clinician',
            'renter_id'          => 'required|integer|min:1',
            'facility_id'        => 'required|exists:facilities,id',
            'expected_return_at' => 'required|date|after:now',
            'notes'              => 'nullable|string',
            'fee_terms'          => 'nullable|string',
        ]);

        $user = $request->user();
        if (!$user->isAdmin()) {
            if ($user->facility_id === null) {
                abort(403, 'Account has no facility assignment.');
            }
            if ((int) $data['facility_id'] !== $user->facility_id) {
                abort(403, 'Cannot check out rentals for another facility.');
            }
        }

        $asset = RentalAsset::findOrFail($data['asset_id']);

        // Same-facility enforcement at the object level.
        if (!$user->isAdmin() && $asset->facility_id !== $user->facility_id) {
            abort(403, 'Asset belongs to another facility.');
        }

        // Cross-entity consistency — the request facility_id must also match
        // the asset's facility so a caller cannot attribute a checkout to a
        // facility that doesn't own the equipment.
        if ((int) $asset->facility_id !== (int) $data['facility_id']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'facility_id' => ['facility_id must match the asset facility.'],
            ]);
        }

        $transaction = $this->rentalService->checkout(
            asset: $asset,
            renterType: $data['renter_type'],
            renterId: $data['renter_id'],
            facilityId: $data['facility_id'],
            expectedReturnAt: \Carbon\Carbon::parse($data['expected_return_at']),
            createdBy: $user->id,
            notes: $data['notes'] ?? null,
            feeTerms: $data['fee_terms'] ?? null,
        );

        return response()->json($transaction->load(['asset']), 201);
    }

    public function return(Request $request, RentalTransaction $rentalTransaction): JsonResponse
    {
        $this->authorize('return', $rentalTransaction);

        $data = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $transaction = $this->rentalService->return(
            $rentalTransaction,
            $request->user()->id,
            $data['notes'] ?? null,
        );

        return response()->json($transaction->load(['asset']));
    }

    public function show(Request $request, RentalTransaction $rentalTransaction): JsonResponse
    {
        $this->authorize('view', $rentalTransaction);

        $rentalTransaction->load(['asset', 'facility']);
        $data = $rentalTransaction->toArray();
        $data['is_overdue'] = $rentalTransaction->isOverdue();
        $data['overdue_minutes'] = $rentalTransaction->overdueMinutes();

        return response()->json($data);
    }

    public function overdueList(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RentalTransaction::class);
        $overdue = $this->rentalService->getOverdueTransactions();

        $user = $request->user();
        if (!$user->isAdmin()) {
            if ($user->facility_id === null) {
                return response()->json([]);
            }
            $overdue = $overdue->where('facility_id', $user->facility_id)->values();
        }

        return response()->json($overdue);
    }

    public function cancel(Request $request, RentalTransaction $rentalTransaction): JsonResponse
    {
        $this->authorize('cancel', $rentalTransaction);

        if ($rentalTransaction->status === 'returned') {
            return response()->json(['message' => 'Cannot cancel a returned transaction.'], 422);
        }

        $old = $rentalTransaction->toArray();
        $rentalTransaction->update([
            'status'     => 'cancelled',
            'updated_by' => $request->user()->id,
        ]);
        $rentalTransaction->asset->update(['status' => 'available']);
        $this->audit->logModel('rental.cancel', $rentalTransaction, $old, $rentalTransaction->fresh()->toArray());

        return response()->json($rentalTransaction->fresh());
    }
}
