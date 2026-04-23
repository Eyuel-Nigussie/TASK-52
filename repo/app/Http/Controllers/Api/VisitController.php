<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByFacility;
use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ServiceOrder;
use App\Models\Visit;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VisitController extends Controller
{
    use ScopesByFacility;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Visit::class);

        $query = Visit::with(['patient', 'doctor', 'review'])
            ->when($request->filled('doctor_id'), fn($q) => $q->where('doctor_id', $request->doctor_id))
            ->when($request->filled('patient_id'), fn($q) => $q->where('patient_id', $request->patient_id))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('date_from'), fn($q) => $q->where('visit_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn($q) => $q->where('visit_date', '<=', $request->date_to))
            ->orderByDesc('visit_date');

        $this->applyFacilityScope($query, $request->user(), $request->integer('facility_id') ?: null);

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Visit::class);

        $data = $request->validate([
            'facility_id'      => 'required|exists:facilities,id',
            'patient_id'       => 'required|exists:patients,id',
            'doctor_id'        => 'required|exists:doctors,id',
            'service_order_id' => 'nullable|exists:service_orders,id',
            'visit_date'       => 'required|date',
            'status'           => 'nullable|in:scheduled,completed,cancelled',
        ]);

        $user = $request->user();
        if (!$user->isAdmin()) {
            if ($user->facility_id === null) {
                abort(403, 'Account has no facility assignment.');
            }
            if ((int) $data['facility_id'] !== $user->facility_id) {
                abort(403, 'Cannot create visits for another facility.');
            }
        }

        // Cross-entity consistency — patient, doctor, and (when provided)
        // service_order must all belong to the same facility as the visit.
        $facilityId = (int) $data['facility_id'];
        $patient = Patient::findOrFail($data['patient_id']);
        if ((int) $patient->facility_id !== $facilityId) {
            throw ValidationException::withMessages([
                'patient_id' => ['Patient belongs to a different facility than this visit.'],
            ]);
        }
        $doctor = Doctor::findOrFail($data['doctor_id']);
        if ((int) $doctor->facility_id !== $facilityId) {
            throw ValidationException::withMessages([
                'doctor_id' => ['Doctor belongs to a different facility than this visit.'],
            ]);
        }
        if (!empty($data['service_order_id'])) {
            $serviceOrder = ServiceOrder::findOrFail($data['service_order_id']);
            if ((int) $serviceOrder->facility_id !== $facilityId) {
                throw ValidationException::withMessages([
                    'service_order_id' => ['Service order belongs to a different facility than this visit.'],
                ]);
            }
        }

        if (($data['status'] ?? null) === 'completed') {
            $data['review_token'] = (string) Str::uuid();
        }

        $visit = Visit::create($data);
        $this->audit->logModel('visit.create', $visit);

        $response = $visit->load(['patient', 'doctor']);
        // Surface the token once on creation so staff can hand it to the pet owner.
        if ($visit->review_token) {
            $response = array_merge($response->toArray(), ['review_token' => $visit->review_token]);
            return response()->json($response, 201);
        }

        return response()->json($response, 201);
    }

    public function show(Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);

        $visit->load(['patient', 'doctor', 'facility', 'review.images', 'review.response']);

        return response()->json($visit);
    }

    public function update(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('update', $visit);

        $data = $request->validate([
            'status'     => 'sometimes|in:scheduled,completed,cancelled',
            'visit_date' => 'sometimes|date',
        ]);

        $old = $visit->toArray();

        // Generate a one-time review token when the visit first becomes completed.
        if (isset($data['status']) && $data['status'] === 'completed' && $visit->status !== 'completed') {
            $data['review_token'] = (string) Str::uuid();
        }

        $visit->update($data);
        $fresh = $visit->fresh();
        $this->audit->logModel('visit.update', $visit, $old, $fresh->toArray());

        // Surface the token once so staff can copy it to the tablet URL.
        if (isset($data['review_token'])) {
            return response()->json(array_merge($fresh->toArray(), ['review_token' => $fresh->review_token]));
        }

        return response()->json($fresh);
    }
}
