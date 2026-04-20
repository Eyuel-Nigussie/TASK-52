<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServicePricing;
use App\Models\User;

/**
 * Pricing rows are facility-scoped. Users read pricing for their own
 * facility; managers maintain it; admins do both across facilities.
 */
class ServicePricingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ServicePricing $pricing): bool
    {
        return $this->sharesFacility($user, $pricing->facility_id);
    }

    public function create(User $user): bool
    {
        return $user->isManager();
    }

    public function update(User $user, ServicePricing $pricing): bool
    {
        return $user->isManager() && $this->sharesFacility($user, $pricing->facility_id);
    }

    public function delete(User $user, ServicePricing $pricing): bool
    {
        return $user->isAdmin();
    }

    private function sharesFacility(User $user, ?int $facilityId): bool
    {
        if ($user->isAdmin() || $user->facility_id === null || $facilityId === null) {
            return true;
        }
        return $user->facility_id === $facilityId;
    }
}
