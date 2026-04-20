<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Services\AuditService;
use App\Services\DataVersioningService;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FacilityController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DataVersioningService $versioning,
        private readonly ImportService $importService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Facility::class);

        $user = $request->user();
        $query = Facility::query()
            ->when($request->boolean('active_only'), fn($q) => $q->active())
            ->when($request->filled('search'), fn($q) => $q->where('name', 'like', '%' . $request->search . '%'))
            ->withCount('departments')
            ->orderBy('name');

        // Scoped user sees only their own facility; admin sees all.
        if (!$user->isAdmin() && $user->facility_id !== null) {
            $query->where('id', $user->facility_id);
        }

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Facility::class);

        $data = $request->validate([
            'external_key'   => 'required|string|max:100|unique:facilities',
            'name'           => 'required|string|max:255',
            'address'        => 'required|string',
            'city'           => 'required|string|max:100',
            'state'          => 'required|string|size:2',
            'zip'            => 'required|string|max:10',
            'email'          => 'nullable|email|max:255',
            'business_hours' => 'nullable|array',
        ]);

        $data['created_by'] = $request->user()->id;

        if ($request->filled('phone')) {
            $data['phone_encrypted'] = encrypt($request->phone);
        }

        $facility = Facility::create($data);
        $this->versioning->record($facility, [], $request->user()->id, 'Created via API');
        $this->audit->logModel('facility.create', $facility);

        return response()->json($facility, 201);
    }

    public function show(Request $request, Facility $facility): JsonResponse
    {
        $this->authorize('view', $facility);

        $user = $request->user();
        $facility->load('departments');
        $data = $facility->toArray();
        $data['phone'] = $user->can('viewUnmaskedPhone', $facility)
            ? $facility->getPhone()
            : $facility->getMaskedPhone();

        return response()->json($data);
    }

    public function update(Request $request, Facility $facility): JsonResponse
    {
        $this->authorize('update', $facility);

        $data = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'address'        => 'sometimes|string',
            'city'           => 'sometimes|string|max:100',
            'state'          => 'sometimes|string|size:2',
            'zip'            => 'sometimes|string|max:10',
            'email'          => 'nullable|email|max:255',
            'business_hours' => 'nullable|array',
            'active'         => 'sometimes|boolean',
        ]);

        $old = $facility->toArray();
        $data['updated_by'] = $request->user()->id;

        if ($request->filled('phone')) {
            $data['phone_encrypted'] = encrypt($request->phone);
        }

        $facility->update($data);
        $this->versioning->record($facility, $old, $request->user()->id, 'Updated via API');
        $this->audit->logModel('facility.update', $facility, $old, $facility->fresh()->toArray());

        return response()->json($facility->fresh());
    }

    public function destroy(Request $request, Facility $facility): JsonResponse
    {
        $this->authorize('delete', $facility);
        $this->audit->logModel('facility.delete', $facility);
        $facility->delete();

        return response()->json(['message' => 'Facility deleted.']);
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorize('create', Facility::class);

        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:' . (config('vetops.upload_max_mb', 20) * 1024),
        ]);

        $import = $this->importService->queueImport($request->file('file'), 'facility', $request->user()->id);
        $import = $this->importService->process($import);

        return response()->json($import);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Facility::class);
        $this->audit->logExport('facility');
        $csv = $this->importService->export('facility');

        $stream = function () use ($csv) {
            echo $csv;
        };

        return new StreamedResponse($stream, 200, ['Content-Type' => 'text/csv']);
    }

    public function history(Request $request, Facility $facility): JsonResponse
    {
        $this->authorize('viewHistory', $facility);
        return response()->json($this->versioning->getHistory($facility));
    }
}
