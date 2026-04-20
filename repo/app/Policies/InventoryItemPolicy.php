<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InventoryItem;
use App\Models\User;

class InventoryItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, InventoryItem $item): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['inventory_clerk', 'system_admin'], true);
    }

    public function update(User $user, InventoryItem $item): bool
    {
        return in_array($user->role, ['inventory_clerk', 'system_admin'], true);
    }

    public function delete(User $user, InventoryItem $item): bool
    {
        return $user->isAdmin();
    }

    public function import(User $user): bool
    {
        return in_array($user->role, ['inventory_clerk', 'system_admin'], true);
    }

    public function export(User $user): bool
    {
        return in_array($user->role, ['inventory_clerk', 'clinic_manager', 'system_admin'], true);
    }
}
