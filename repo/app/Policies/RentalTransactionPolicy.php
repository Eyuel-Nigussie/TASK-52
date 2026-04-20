<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RentalTransaction;
use App\Models\User;

class RentalTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RentalTransaction $tx): bool
    {
        return $this->sharesFacility($user, $tx->facility_id);
    }

    public function checkout(User $user): bool
    {
        return in_array($user->role, ['inventory_clerk', 'clinic_manager', 'technician_doctor', 'system_admin'], true);
    }

    public function return(User $user, RentalTransaction $tx): bool
    {
        return $this->sharesFacility($user, $tx->facility_id);
    }

    public function cancel(User $user, RentalTransaction $tx): bool
    {
        return in_array($user->role, ['clinic_manager', 'system_admin'], true)
            && $this->sharesFacility($user, $tx->facility_id);
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
