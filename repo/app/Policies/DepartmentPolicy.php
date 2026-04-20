<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

/**
 * Departments are clinic-scoped operational data. Non-admin users may only
 * inspect or mutate departments within their own facility; system_admin is
 * granted blanket access via the global Gate::before short-circuit.
 */
class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        // Managers can see their own facility's departments; everyone else
        // (technicians, inventory clerks) legitimately needs the list to
        // populate dropdowns, but the controller still scopes by facility.
        return true;
    }

    public function view(User $user, Department $department): bool
    {
        return $this->sharesFacility($user, $department->facility_id);
    }

    public function create(User $user): bool
    {
        return $user->isManager();
    }

    public function update(User $user, Department $department): bool
    {
        return $user->isManager() && $this->sharesFacility($user, $department->facility_id);
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->isAdmin();
    }

    private function sharesFacility(User $user, ?int $facilityId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        // Non-admin users must be explicitly bound to a facility and that
        // facility must match the record. UserController already blocks new
        // accounts from landing in the null-facility state; any remaining
        // legacy rows are denied here rather than treated as permissive.
        return $user->facility_id !== null
            && $user->facility_id === $facilityId;
    }
}
