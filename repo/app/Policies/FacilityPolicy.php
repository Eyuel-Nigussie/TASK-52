<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Facility;
use App\Models\User;

/**
 * Facility records are tenant-confidential metadata (addresses, phone,
 * business hours). We still allow authenticated users to hit the list
 * endpoint — the controller query applies `applyFacilityScope()` so
 * scoped users only see their own facility — but the object-level
 * `view` ability refuses cross-facility lookups by id.
 */
class FacilityPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Facility $facility): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->facility_id !== null) {
            return $user->facility_id === $facility->id;
        }
        // Legacy unassigned non-admin — UserController blocks creating new ones.
        return true;
    }

    public function viewUnmaskedPhone(User $user, Facility $facility): bool
    {
        // Facility phone numbers are sensitive operational contact data; keep
        // them admin-only to match the established convention across the app.
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isManager();
    }

    public function update(User $user, Facility $facility): bool
    {
        return $user->isManager() && $this->view($user, $facility);
    }

    public function delete(User $user, Facility $facility): bool
    {
        return $user->isAdmin();
    }

    public function viewHistory(User $user, Facility $facility): bool
    {
        return $user->isManager() && $this->view($user, $facility);
    }
}
