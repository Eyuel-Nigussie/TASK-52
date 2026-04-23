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
        if ($user->facility_id === null) {
            return false;
        }
        return $user->facility_id === $visit->facility_id;
    }

    public function create(User $user): bool
    {
        return $user->isClinicalRole();
    }

    public function update(User $user, Visit $visit): bool
    {
        return $user->isClinicalRole() && $this->view($user, $visit);
    }
}
