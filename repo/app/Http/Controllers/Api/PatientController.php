<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByFacility;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\AuditService;
use App\Services\DataVersioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    use ScopesByFacility;

    public function __construct(
        private readonly AuditService $audit,
        private readonly DataVersioningService $versioning,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);

        $query = Patient::with('facility')
            ->when($request->filled('search'), fn($q) => $q->where(function ($inner) use ($request) {
                $inner->where('name', 'like', '%' . $request->search . '%')
                      ->orWhere('owner_name', 'like', '%' . $request->search . '%');
            }))
            ->when($request->boolean('active_only'), fn($q) => $q->active())
            ->orderBy('name');

        $this->applyFacilityScope($query, $request->user(), $request->integer('facility_id') ?: null);

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Patient::class);

        $data = $request->validate([
            'facility_id'   => 'required|exists:facilities,id',
            'external_key'  => 'required|string|max:100',
            'name'          => 'required|string|max:100',
            'species'       => 'nullable|string|max:50',
            'breed'         => 'nullable|string|max:100',
            'owner_name'    => 'nullable|string|max:150',
            'owner_email'   => 'nullable|email',
        ]);

        $user = $request->user();
        if (!$user->isAdmin() && $user->facility_id !== null && (int) $data['facility_id'] !== $user->facility_id) {
            abort(403, 'Cannot create patients for another facility.');
        }

        if ($request->filled('owner_phone')) {
            $data['owner_phone_encrypted'] = encrypt($request->owner_phone);
        }

        $patient = Patient::create($data);

        $this->versioning->record($patient, [], $user->id, 'Created via API');
        $this->audit->logModel('patient.create', $patient);

        return response()->json($patient, 201);
    }

    public function show(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $patient->load('facility');
        $data = $patient->toArray();
        $data['owner_phone'] = $request->user()->can('viewUnmaskedPhone', $patient)
            ? $patient->getOwnerPhone()
            : $patient->getMaskedOwnerPhone();

        return response()->json($data);
    }

    public function update(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('update', $patient);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'species'     => 'nullable|string|max:50',
            'breed'       => 'nullable|string|max:100',
            'owner_name'  => 'nullable|string|max:150',
            'owner_email' => 'nullable|email',
            'active'      => 'sometimes|boolean',
        ]);

        if ($request->filled('owner_phone')) {
            $data['owner_phone_encrypted'] = encrypt($request->owner_phone);
        }

        $old = $patient->toArray();
        $patient->update($data);

        $this->versioning->record($patient, $old, $request->user()->id, 'Updated via API');
        $this->audit->logModel('patient.update', $patient, $old, $patient->fresh()->toArray());

        return response()->json($patient->fresh());
    }

    public function destroy(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('delete', $patient);

        $this->audit->logModel('patient.delete', $patient);
        $patient->delete();

        return response()->json(['message' => 'Patient deleted.']);
    }
}
