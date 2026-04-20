<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Visit;

class VisitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Visit $visit): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->facility_id !== null) {
            return $user->facility_id === $visit->facility_id;
        }
        // Legacy unassigned non-admin — UserController now blocks creating such users.
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Visit $visit): bool
    {
        return $this->view($user, $visit);
    }
}
