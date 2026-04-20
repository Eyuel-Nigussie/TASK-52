<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MergeRequest;
use App\Models\User;

class MergeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isManager();
    }

    public function view(User $user, MergeRequest $request): bool
    {
        return $user->isManager() && $this->sharesFacility($user, $request);
    }

    public function create(User $user): bool
    {
        return $user->isManager();
    }

    public function approve(User $user, MergeRequest $request): bool
    {
        return $user->isManager() && $this->sharesFacility($user, $request);
    }

    public function reject(User $user, MergeRequest $request): bool
    {
        return $user->isManager() && $this->sharesFacility($user, $request);
    }

    /**
     * A manager can only approve/reject merge requests for their own facility.
     * Pre-migration rows (facility_id = null) are only visible/mutable by
     * system_admin, which is already granted blanket access by Gate::before.
     */
    private function sharesFacility(User $user, MergeRequest $request): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // If both sides are unassigned, allow manager for legacy/test rows.
        if ($user->facility_id === null && $request->facility_id === null) {
            return true;
        }

        // Non-admin managers must be bound to a facility AND the merge row
        // must be tagged with the same facility.
        return $user->facility_id !== null
            && $request->facility_id !== null
            && $user->facility_id === $request->facility_id;
    }
}
