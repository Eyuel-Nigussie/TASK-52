<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByFacility;
use App\Http\Controllers\Controller;
use App\Models\Storeroom;
use App\Services\AuditService;
use App\Services\DataVersioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreroomController extends Controller
{
    use ScopesByFacility;

    public function __construct(
        private readonly AuditService $audit,
        private readonly DataVersioningService $versioning,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Storeroom::class);

        $query = Storeroom::with('facility')
            ->when($request->boolean('active_only'), fn($q) => $q->active())
            ->orderBy('name');

        $this->applyFacilityScope($query, $request->user(), $request->integer('facility_id') ?: null);

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Storeroom::class);

        $data = $request->validate([
            'facility_id' => 'required|exists:facilities,id',
            'name'        => 'required|string|max:255',
            'code'        => 'nullable|string|max:20',
        ]);

        $user = $request->user();
        if (!$user->isAdmin()) {
            if ($user->facility_id === null) {
                abort(403, 'Account has no facility assignment.');
            }
            if ((int) $data['facility_id'] !== $user->facility_id) {
                abort(403, 'Cannot create storerooms for another facility.');
            }
        }

        $storeroom = Storeroom::create($data);
        $this->audit->logModel('storeroom.create', $storeroom);

        return response()->json($storeroom, 201);
    }

    public function update(Request $request, Storeroom $storeroom): JsonResponse
    {
        $this->authorize('update', $storeroom);

        $data = $request->validate([
            'name'   => 'sometimes|string|max:255',
            'code'   => 'nullable|string|max:20',
            'active' => 'sometimes|boolean',
        ]);

        $old = $storeroom->toArray();
        $storeroom->update($data);
        $this->audit->logModel('storeroom.update', $storeroom, $old, $storeroom->fresh()->toArray());
        $this->versioning->record($storeroom, $old, $request->user()->id, 'Updated via API');

        return response()->json($storeroom->fresh());
    }

    public function history(Storeroom $storeroom): JsonResponse
    {
        $this->authorize('view', $storeroom);

        return response()->json($this->versioning->getHistory($storeroom));
    }

    public function destroy(Storeroom $storeroom): JsonResponse
    {
        $this->authorize('delete', $storeroom);
        $this->audit->logModel('storeroom.delete', $storeroom);
        $storeroom->delete();

        return response()->json(['message' => 'Storeroom deleted.']);
    }
}
