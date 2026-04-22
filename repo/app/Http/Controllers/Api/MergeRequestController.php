<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MergeRequest;
use App\Services\AuditService;
use App\Services\MergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MergeRequestController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly MergeService $mergeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MergeRequest::class);

        $user = $request->user();
        $query = MergeRequest::with(['requestedBy', 'resolvedBy'])
            ->when($request->filled('entity_type'), fn($q) => $q->where('entity_type', $request->entity_type))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at');

        if (!$user->isAdmin()) {
            if ($user->facility_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('facility_id', $user->facility_id);
            }
        }

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MergeRequest::class);

        $data = $request->validate([
            'entity_type'      => 'required|string|max:100',
            'source_id'        => 'required|integer|min:1',
            'target_id'        => 'required|integer|min:1|different:source_id',
            'conflict_data'    => 'nullable|array',
            'resolution_rules' => 'nullable|array',
        ]);

        if (!MergeService::isSupported($data['entity_type'])) {
            return response()->json([
                'message' => "Merge not supported for entity type '{$data['entity_type']}'.",
                'errors'  => ['entity_type' => ["Unsupported entity type '{$data['entity_type']}'."]],
            ], 422);
        }

        // Derive the merge-request's facility_id from the source entity if
        // that entity has a facility_id column. This ties the merge request
        // into the tenant-isolation boundary for the listing/approval flow.
        $facilityId = $this->resolveFacilityForSource($data['entity_type'], (int) $data['source_id']);

        $user = $request->user();
        if (!$user->isAdmin()) {
            if ($user->facility_id === null) {
                abort(403, 'Account has no facility assignment.');
            }
            if ($facilityId !== null && $facilityId !== $user->facility_id) {
                abort(403, 'Cannot create merge requests for another facility.');
            }
        }

        $merge = MergeRequest::create([
            ...$data,
            'facility_id'  => $facilityId,
            'status'       => 'pending',
            'requested_by' => $user->id,
        ]);

        $this->audit->logModel('merge_request.create', $merge);

        return response()->json($merge, 201);
    }

    public function approve(Request $request, MergeRequest $mergeRequest): JsonResponse
    {
        $this->authorize('approve', $mergeRequest);

        // MergeService enforces state, facility match, and entity support.
        // It relinks foreign keys, snapshots both records, writes audit,
        // then soft-deletes the source — all inside a DB transaction.
        $merge = $this->mergeService->execute($mergeRequest, $request->user());

        $this->audit->logModel('merge_request.approve', $merge);

        return response()->json($merge);
    }

    public function reject(Request $request, MergeRequest $mergeRequest): JsonResponse
    {
        $this->authorize('reject', $mergeRequest);

        if ($mergeRequest->status !== 'pending') {
            return response()->json(['message' => 'Merge request is not pending.'], 422);
        }

        $mergeRequest->update([
            'status'      => 'rejected',
            'resolved_by' => $request->user()->id,
        ]);

        $this->audit->logModel('merge_request.reject', $mergeRequest);

        return response()->json($mergeRequest->fresh());
    }

    /**
     * Look up the facility_id of the source entity being merged so the
     * merge-request row inherits the right tenant scope.
     */
    private function resolveFacilityForSource(string $entityType, int $sourceId): ?int
    {
        $model = match ($entityType) {
            'patient'      => \App\Models\Patient::class,
            'doctor'       => \App\Models\Doctor::class,
            'rental_asset' => \App\Models\RentalAsset::class,
            'service'      => \App\Models\Service::class,
            default        => null,
        };

        if ($model === null) {
            return null;
        }

        $record = $model::query()->find($sourceId);
        if ($record === null) {
            return null;
        }

        return property_exists($record, 'facility_id') || isset($record->facility_id)
            ? ($record->facility_id ?? null)
            : null;
    }
}
