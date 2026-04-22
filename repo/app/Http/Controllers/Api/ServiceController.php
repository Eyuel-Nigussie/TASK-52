<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServicePricing;
use App\Services\AuditService;
use App\Services\DataVersioningService;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServiceController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DataVersioningService $versioning,
        private readonly ImportService $importService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Service::class);

        $services = Service::query()
            ->when($request->filled('category'), fn($q) => $q->where('category', $request->category))
            ->when($request->filled('search'), fn($q) => $q->where('name', 'like', '%' . $request->search . '%'))
            ->when($request->boolean('active_only'), fn($q) => $q->active())
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json($services);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Service::class);

        $data = $request->validate([
            'external_key'     => 'required|string|max:100|unique:services',
            'name'             => 'required|string|max:255',
            'category'         => 'required|string|max:100',
            'code'             => 'nullable|string|max:50',
            'description'      => 'nullable|string|max:2000',
            'duration_minutes' => 'nullable|integer|min:1|max:1440',
            'active'           => 'sometimes|boolean',
        ]);

        $service = Service::create($data);

        $this->versioning->record($service, [], $request->user()->id, 'Created via API');
        $this->audit->logModel('service.create', $service);

        return response()->json($service, 201);
    }

    public function show(Service $service): JsonResponse
    {
        $this->authorize('view', $service);
        $service->load('pricings.facility');
        return response()->json($service);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $this->authorize('update', $service);

        $data = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'category'         => 'sometimes|string|max:100',
            'code'             => 'nullable|string|max:50',
            'description'      => 'nullable|string|max:2000',
            'duration_minutes' => 'nullable|integer|min:1|max:1440',
            'active'           => 'sometimes|boolean',
        ]);

        $old = $service->toArray();
        $service->update($data);

        $this->versioning->record($service, $old, $request->user()->id, 'Updated via API');
        $this->audit->logModel('service.update', $service, $old, $service->fresh()->toArray());

        return response()->json($service->fresh());
    }

    public function destroy(Service $service): JsonResponse
    {
        $this->authorize('delete', $service);
        $this->audit->logModel('service.delete', $service);
        $service->delete();

        return response()->json(['message' => 'Service deleted.']);
    }

    public function pricings(Request $request, Service $service): JsonResponse
    {
        $this->authorize('view', $service);

        $user = $request->user();
        $query = $service->pricings()->with('facility');

        if (!$user->isAdmin()) {
            if ($user->facility_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('facility_id', $user->facility_id);
            }
        } elseif ($request->filled('facility_id')) {
            $query->where('facility_id', $request->facility_id);
        }

        return response()->json($query->orderByDesc('effective_from')->get());
    }

    public function storePricing(Request $request, Service $service): JsonResponse
    {
        $this->authorize('create', ServicePricing::class);

        $data = $request->validate([
            'facility_id'    => 'required|exists:facilities,id',
            'base_price'     => 'required|numeric|min:0',
            'currency'       => 'nullable|string|size:3',
            'effective_from' => 'required|date',
            'effective_to'   => 'nullable|date|after_or_equal:effective_from',
            'active'         => 'sometimes|boolean',
        ]);

        $user = $request->user();
        if (!$user->isAdmin()) {
            if ($user->facility_id === null) {
                abort(403, 'Account has no facility assignment.');
            }
            if ((int) $data['facility_id'] !== $user->facility_id) {
                abort(403, 'Cannot set pricing for another facility.');
            }
        }

        $data['service_id'] = $service->id;
        $pricing = ServicePricing::create($data);

        $this->versioning->record($pricing, [], $user->id, 'Pricing created via API');
        $this->audit->logModel('service_pricing.create', $pricing);

        return response()->json($pricing, 201);
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorize('create', Service::class);

        $request->validate(['file' => 'required|file|mimes:csv,txt|max:' . (config('vetops.upload_max_mb') * 1024)]);
        $import = $this->importService->queueImport($request->file('file'), 'service', $request->user()->id);
        $import = $this->importService->process($import);
        return response()->json($import);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Service::class);
        $this->audit->logExport('service');
        $csv = $this->importService->export('service');

        return new StreamedResponse(function () use ($csv) {
            echo $csv;
        }, 200, ['Content-Type' => 'text/csv']);
    }
}
