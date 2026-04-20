<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Doctor;
use App\Models\User;

class DoctorPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Doctor $doctor): bool
    {
        return $this->sharesFacility($user, $doctor->facility_id);
    }

    public function viewUnmaskedPhone(User $user, Doctor $doctor): bool
    {
        return $user->isManager() && $this->view($user, $doctor);
    }

    public function create(User $user): bool
    {
        return $user->isManager();
    }

    public function update(User $user, Doctor $doctor): bool
    {
        return $user->isManager() && $this->view($user, $doctor);
    }

    public function delete(User $user, Doctor $doctor): bool
    {
        return $user->isAdmin();
    }

    private function sharesFacility(User $user, ?int $facilityId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->facility_id !== null) {
            return $user->facility_id === $facilityId;
        }
        return true;
    }
}
