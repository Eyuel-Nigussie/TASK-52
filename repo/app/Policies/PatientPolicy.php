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
        if ($user->facility_id === null) {
            return false;
        }
        return $user->facility_id === $patient->facility_id;
    }

    public function viewUnmaskedPhone(User $user, Patient $patient): bool
    {
        return $user->isManager() && $this->view($user, $patient);
    }

    public function create(User $user): bool
    {
        return $user->isClinicalRole();
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->isClinicalRole() && $this->view($user, $patient);
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $user->isManager() && $this->view($user, $patient);
    }
}
