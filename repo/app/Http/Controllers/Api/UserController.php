<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DataVersioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DataVersioningService $versioning,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $users = User::with(['facility', 'department'])
            ->when($request->filled('facility_id'), fn($q) => $q->where('facility_id', $request->facility_id))
            ->when($request->filled('role'), fn($q) => $q->where('role', $request->role))
            ->when($request->boolean('active_only'), fn($q) => $q->active())
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username'      => 'required|string|max:64|unique:users',
            'name'          => 'required|string|max:255',
            'email'         => 'nullable|email|unique:users',
            'password'      => ['required', 'string', 'min:12', 'confirmed', Password::min(12)],
            'role'          => 'required|in:' . implode(',', array_keys(config('vetops.roles'))),
            'facility_id'   => 'nullable|exists:facilities,id',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        // system_admin is the only role that may have no facility. All other
        // roles must always be assigned to a facility, regardless of who is
        // making the request.
        if ($data['role'] !== 'system_admin' && empty($data['facility_id'])) {
            return response()->json([
                'message' => 'facility_id is required for non-admin roles.',
                'errors'  => ['facility_id' => ['Non-admin roles must be assigned to a facility.']],
            ], 422);
        }

        if ($request->filled('phone')) {
            $data['phone_encrypted'] = encrypt($request->phone);
        }

        $data['password_changed_at'] = now();
        $user = User::create($data);
        $this->audit->logModel('user.create', $user, null, ['username' => $user->username, 'role' => $user->role]);
        $this->versioning->record($user, [], $request->user()->id, 'Created via API');

        return response()->json($user, 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $user->load(['facility', 'department']);
        $data = $user->toArray();
        $data['phone'] = $request->user()->isAdmin() ? $user->getPhone() : $user->getMaskedPhone();

        return response()->json($data);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'email'         => 'nullable|email|unique:users,email,' . $user->id,
            'role'          => 'sometimes|in:' . implode(',', array_keys(config('vetops.roles'))),
            'facility_id'   => 'nullable|exists:facilities,id',
            'department_id' => 'nullable|exists:departments,id',
            'active'        => 'sometimes|boolean',
        ]);

        // Only enforce the facility requirement when role or facility_id is
        // actually being changed. Legacy rows with null facility may have
        // other fields (active, name, email) updated without touching the
        // assignment. Explicitly clearing facility_id or changing a role to a
        // non-admin one without providing a facility must always be rejected.
        $nextRole = $data['role'] ?? $user->role;
        $nextFacilityId = array_key_exists('facility_id', $data) ? $data['facility_id'] : $user->facility_id;
        $roleChanging = isset($data['role']);
        $facilityChanging = array_key_exists('facility_id', $data);
        if (($roleChanging || $facilityChanging) && $nextRole !== 'system_admin' && $nextFacilityId === null) {
            return response()->json([
                'message' => 'facility_id is required for non-admin roles.',
                'errors'  => ['facility_id' => ['Non-admin roles must be assigned to a facility.']],
            ], 422);
        }

        $old = $user->toArray();
        $user->update($data);
        $this->audit->logModel('user.update', $user, $old, $user->fresh()->toArray());
        $this->versioning->record($user, $old, $request->user()->id, 'Updated via API');

        return response()->json($user->fresh());
    }

    public function history(User $user): JsonResponse
    {
        $this->authorize('update', $user);

        return response()->json($this->versioning->getHistory($user));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot delete your own account.'], 422);
        }

        $this->audit->logModel('user.delete', $user);
        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }
}
