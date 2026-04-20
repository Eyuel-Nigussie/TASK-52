<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByFacility;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    use ScopesByFacility;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Department::class);

        $query = Department::with('facility')
            ->when($request->filled('facility_id'), fn($q) => $q->where('facility_id', $request->facility_id))
            ->when($request->boolean('active_only'), fn($q) => $q->active())
            ->orderBy('name');

        // Non-admins are pinned to their own facility regardless of ?facility_id=.
        $this->applyFacilityScope($query, $request->user(), $request->integer('facility_id') ?: null);

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Department::class);

        $data = $request->validate([
            'facility_id'  => 'required|exists:facilities,id',
            'external_key' => 'required|string|max:100',
            'name'         => 'required|string|max:255',
            'code'         => 'nullable|string|max:20',
        ]);

        $user = $request->user();
        if (!$user->isAdmin() && $user->facility_id !== null && (int) $data['facility_id'] !== $user->facility_id) {
            abort(403, 'Cannot create departments for another facility.');
        }

        $dept = Department::create($data);
        $this->audit->logModel('department.create', $dept);

        return response()->json($dept, 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $this->authorize('update', $department);

        $data = $request->validate([
            'name'   => 'sometimes|string|max:255',
            'code'   => 'nullable|string|max:20',
            'active' => 'sometimes|boolean',
        ]);

        $old = $department->toArray();
        $department->update($data);
        $this->audit->logModel('department.update', $department, $old, $department->fresh()->toArray());

        return response()->json($department->fresh());
    }

    public function destroy(Request $request, Department $department): JsonResponse
    {
        $this->authorize('delete', $department);

        $this->audit->logModel('department.delete', $department);
        $department->delete();

        return response()->json(['message' => 'Department deleted.']);
    }
}
