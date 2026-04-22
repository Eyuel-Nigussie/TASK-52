<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServiceOrder;
use App\Models\User;

class ServiceOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ServiceOrder $order): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->facility_id === null) {
            return false;
        }
        return $user->facility_id === $order->facility_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function close(User $user, ServiceOrder $order): bool
    {
        return in_array($user->role, ['technician_doctor', 'clinic_manager', 'system_admin'], true)
            && $this->view($user, $order);
    }

    public function addReservation(User $user, ServiceOrder $order): bool
    {
        return $this->view($user, $order);
    }
}
