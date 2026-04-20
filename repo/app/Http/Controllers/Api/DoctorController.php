<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByFacility;
use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Services\AuditService;
use App\Services\DataVersioningService;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    use ScopesByFacility;

    public function __construct(
        private readonly ImportService $importService,
        private readonly AuditService $audit,
        private readonly DataVersioningService $versioning,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Doctor::class);

        $query = Doctor::with('facility')
            ->when($request->boolean('active_only'), fn($q) => $q->active())
            ->orderBy('last_name');

        $this->applyFacilityScope($query, $request->user(), $request->integer('facility_id') ?: null);

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Doctor::class);

        $data = $request->validate([
            'facility_id'    => 'required|exists:facilities,id',
            'external_key'   => 'required|string|max:100',
            'first_name'     => 'required|string|max:100',
            'last_name'      => 'required|string|max:100',
            'specialty'      => 'nullable|string|max:100',
            'license_number' => 'nullable|string|max:50|unique:doctors',
            'email'          => 'nullable|email',
        ]);

        $user = $request->user();
        if (!$user->isAdmin() && $user->facility_id !== null && (int) $data['facility_id'] !== $user->facility_id) {
            abort(403, 'Cannot create doctors for another facility.');
        }

        if ($request->filled('phone')) {
            $data['phone_encrypted'] = encrypt($request->phone);
        }

        $doctor = Doctor::create($data);
        $this->versioning->record($doctor, [], $user->id, 'Created via API');
        $this->audit->logModel('doctor.create', $doctor);

        return response()->json($doctor, 201);
    }

    public function show(Request $request, Doctor $doctor): JsonResponse
    {
        $this->authorize('view', $doctor);

        $doctor->load('facility');
        $data = $doctor->toArray();
        $data['phone'] = $request->user()->can('viewUnmaskedPhone', $doctor)
            ? $doctor->getPhone()
            : $doctor->getMaskedPhone();

        return response()->json($data);
    }

    public function update(Request $request, Doctor $doctor): JsonResponse
    {
        $this->authorize('update', $doctor);

        $data = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'last_name'  => 'sometimes|string|max:100',
            'specialty'  => 'nullable|string|max:100',
            'email'      => 'nullable|email',
            'active'     => 'sometimes|boolean',
        ]);

        if ($request->filled('phone')) {
            $data['phone_encrypted'] = encrypt($request->phone);
        }

        $old = $doctor->toArray();
        $doctor->update($data);

        $this->versioning->record($doctor, $old, $request->user()->id, 'Updated via API');
        $this->audit->logModel('doctor.update', $doctor, $old, $doctor->fresh()->toArray());

        return response()->json($doctor->fresh());
    }

    public function destroy(Request $request, Doctor $doctor): JsonResponse
    {
        $this->authorize('delete', $doctor);

        $this->audit->logModel('doctor.delete', $doctor);
        $doctor->delete();

        return response()->json(['message' => 'Doctor deleted.']);
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorize('create', Doctor::class);
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:' . (config('vetops.upload_max_mb') * 1024)]);
        $import = $this->importService->queueImport($request->file('file'), 'doctor', $request->user()->id);
        $import = $this->importService->process($import);
        return response()->json($import);
    }
}
