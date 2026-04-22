<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByFacility;
use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\InventoryItem;
use App\Models\Patient;
use App\Models\ServiceOrder;
use App\Models\Storeroom;
use App\Services\AuditService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceOrderController extends Controller
{
    use ScopesByFacility;

    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceOrder::class);

        $query = ServiceOrder::query()
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->with(['reservations.item', 'reservations.storeroom'])
            ->orderByDesc('created_at');

        $this->applyFacilityScope($query, $request->user(), $request->integer('facility_id') ?: null);

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ServiceOrder::class);

        $data = $request->validate([
            'facility_id'          => 'required|exists:facilities,id',
            'patient_id'           => 'nullable|exists:patients,id',
            'doctor_id'            => 'nullable|exists:doctors,id',
            'reservation_strategy' => 'required|in:lock_at_creation,deduct_at_close',
            'items'                => 'nullable|array',
            'items.*.item_id'      => 'required_with:items|exists:inventory_items,id',
            'items.*.storeroom_id' => 'required_with:items|exists:storerooms,id',
            'items.*.quantity'     => 'required_with:items|numeric|min:0.001',
        ]);

        $user = $request->user();
        if (!$user->isAdmin()) {
            if ($user->facility_id === null) {
                abort(403, 'Account has no facility assignment.');
            }
            if ((int) $data['facility_id'] !== $user->facility_id) {
                abort(403, 'Cannot create orders for another facility.');
            }
        }

        // Cross-entity facility consistency — any referenced patient/doctor
        // must belong to the same facility as this order.
        $facilityId = (int) $data['facility_id'];
        if (!empty($data['patient_id'])) {
            $patient = Patient::findOrFail($data['patient_id']);
            if ((int) $patient->facility_id !== $facilityId) {
                throw ValidationException::withMessages([
                    'patient_id' => ['Patient belongs to a different facility than this order.'],
                ]);
            }
        }
        if (!empty($data['doctor_id'])) {
            $doctor = Doctor::findOrFail($data['doctor_id']);
            if ((int) $doctor->facility_id !== $facilityId) {
                throw ValidationException::withMessages([
                    'doctor_id' => ['Doctor belongs to a different facility than this order.'],
                ]);
            }
        }

        $order = ServiceOrder::create([
            'facility_id'          => $data['facility_id'],
            'patient_id'           => $data['patient_id'] ?? null,
            'doctor_id'            => $data['doctor_id'] ?? null,
            'status'               => 'open',
            'reservation_strategy' => $data['reservation_strategy'],
            'created_by'           => $user->id,
        ]);

        if ($data['reservation_strategy'] === 'lock_at_creation' && !empty($data['items'])) {
            foreach ($data['items'] as $itemData) {
                $this->inventoryService->reserveForOrder(
                    $order,
                    InventoryItem::findOrFail($itemData['item_id']),
                    Storeroom::findOrFail($itemData['storeroom_id']),
                    (float) $itemData['quantity'],
                );
            }
        }

        $this->audit->logModel('service_order.create', $order);

        return response()->json($order->load('reservations'), 201);
    }

    public function show(ServiceOrder $serviceOrder): JsonResponse
    {
        $this->authorize('view', $serviceOrder);

        $serviceOrder->load(['reservations.item', 'reservations.storeroom']);
        return response()->json($serviceOrder);
    }

    public function close(Request $request, ServiceOrder $serviceOrder): JsonResponse
    {
        $this->authorize('close', $serviceOrder);

        if ($serviceOrder->status !== 'open') {
            return response()->json(['message' => 'Order is not open.'], 422);
        }

        $this->inventoryService->closeOrderReservations($serviceOrder);
        $old = $serviceOrder->toArray();
        $serviceOrder->update(['status' => 'closed', 'closed_at' => now()]);
        $this->audit->logModel('service_order.close', $serviceOrder, $old, $serviceOrder->fresh()->toArray());

        return response()->json($serviceOrder->fresh()->load('reservations'));
    }

    public function addReservation(Request $request, ServiceOrder $serviceOrder): JsonResponse
    {
        $this->authorize('addReservation', $serviceOrder);

        $data = $request->validate([
            'item_id'      => 'required|exists:inventory_items,id',
            'storeroom_id' => 'required|exists:storerooms,id',
            'quantity'     => 'required|numeric|min:0.001',
        ]);

        $reservation = $this->inventoryService->reserveForOrder(
            $serviceOrder,
            InventoryItem::findOrFail($data['item_id']),
            Storeroom::findOrFail($data['storeroom_id']),
            (float) $data['quantity'],
        );

        $this->audit->logModel('service_order.reserve', $reservation);

        return response()->json($reservation, 201);
    }
}
