<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StocktakeSession;
use App\Models\Storeroom;
use App\Models\User;

class StocktakeSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StocktakeSession $session): bool
    {
        return $this->sharesFacility($user, $session);
    }

    /**
     * Starting a session requires the storeroom to live in the user's facility.
     * We accept either a Storeroom instance or an id so the controller can
     * gate-check before loading the model.
     */
    public function start(User $user, Storeroom|int|null $storeroom = null): bool
    {
        if (!in_array($user->role, ['inventory_clerk', 'clinic_manager', 'system_admin'], true)) {
            return false;
        }
        if ($storeroom === null) {
            return true;
        }
        $facilityId = $storeroom instanceof Storeroom
            ? $storeroom->facility_id
            : Storeroom::query()->whereKey($storeroom)->value('facility_id');

        return $this->userOwnsFacility($user, $facilityId);
    }

    public function addEntry(User $user, StocktakeSession $session): bool
    {
        return in_array($user->role, ['inventory_clerk', 'clinic_manager', 'system_admin'], true)
            && $session->status === 'open'
            && $this->sharesFacility($user, $session);
    }

    public function approve(User $user, StocktakeSession $session): bool
    {
        return $user->isManager() && $this->sharesFacility($user, $session);
    }

    public function close(User $user, StocktakeSession $session): bool
    {
        return in_array($user->role, ['inventory_clerk', 'clinic_manager', 'system_admin'], true)
            && $this->sharesFacility($user, $session);
    }

    private function sharesFacility(User $user, StocktakeSession $session): bool
    {
        $storeroom = $session->storeroom ?? Storeroom::query()->whereKey($session->storeroom_id)->first();
        if ($storeroom === null) {
            return true;
        }
        return $this->userOwnsFacility($user, $storeroom->facility_id);
    }

    private function userOwnsFacility(User $user, ?int $facilityId): bool
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
