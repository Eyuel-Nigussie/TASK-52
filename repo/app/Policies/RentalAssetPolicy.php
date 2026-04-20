<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RentalAsset;
use App\Models\User;

class RentalAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RentalAsset $asset): bool
    {
        return $this->sharesFacility($user, $asset->facility_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['inventory_clerk', 'clinic_manager', 'system_admin'], true);
    }

    public function update(User $user, RentalAsset $asset): bool
    {
        return $this->create($user) && $this->sharesFacility($user, $asset->facility_id);
    }

    public function delete(User $user, RentalAsset $asset): bool
    {
        return in_array($user->role, ['clinic_manager', 'system_admin'], true)
            && $this->sharesFacility($user, $asset->facility_id);
    }

    public function uploadPhoto(User $user, RentalAsset $asset): bool
    {
        return $this->update($user, $asset);
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
