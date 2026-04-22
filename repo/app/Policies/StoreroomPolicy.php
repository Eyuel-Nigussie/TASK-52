<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Storeroom;
use App\Models\User;

class StoreroomPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Storeroom $storeroom): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->facility_id !== null) {
            return $user->facility_id === $storeroom->facility_id;
        }
        return false;
    }

    public function create(User $user): bool
    {
        return $user->isManager();
    }

    public function update(User $user, Storeroom $storeroom): bool
    {
        return $user->isManager() && $this->view($user, $storeroom);
    }

    public function delete(User $user, Storeroom $storeroom): bool
    {
        return $user->isAdmin();
    }
}
