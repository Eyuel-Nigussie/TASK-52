<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

/**
 * Services are a cross-facility catalog — any authenticated user may read.
 * Mutations are restricted to managers; delete is admin-only.
 */
class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Service $service): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isManager();
    }

    public function update(User $user, Service $service): bool
    {
        return $user->isManager();
    }

    public function delete(User $user, Service $service): bool
    {
        return $user->isAdmin();
    }
}
