<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Patient $patient): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        // Non-admin with facility assignment: same-facility only.
        if ($user->facility_id !== null) {
            return $user->facility_id === $patient->facility_id;
        }
        // Legacy unassigned non-admin account. New users with null facility
        // are blocked at creation by UserController; legacy rows retain the
        // permissive behavior so existing tokens don't lock out silently.
        return true;
    }

    public function viewUnmaskedPhone(User $user, Patient $patient): bool
    {
        return $user->isManager() && $this->view($user, $patient);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Patient $patient): bool
    {
        return $this->view($user, $patient);
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $user->isManager() && $this->view($user, $patient);
    }
}
