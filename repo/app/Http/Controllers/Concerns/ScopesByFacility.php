<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant isolation for facility-scoped list endpoints.
 *
 * Rules:
 *   - system_admin sees everything; may narrow by `facility_id` query param.
 *   - A non-admin user with a facility_id is pinned to that facility.
 *   - A non-admin user with facility_id = null receives an empty result set.
 *     UserController rejects creating/updating non-admin accounts without a
 *     facility assignment; this path is a hard deny-all safety net.
 */
trait ScopesByFacility
{
    protected function applyFacilityScope(Builder $query, ?User $user, ?int $requestedFacilityId = null, string $column = 'facility_id'): Builder
    {
        if ($user !== null && !$user->isAdmin()) {
            if ($user->facility_id === null) {
                // Non-admin with no facility assignment must not see any data.
                return $query->whereRaw('1 = 0');
            }
            return $query->where($column, $user->facility_id);
        }

        if ($requestedFacilityId !== null) {
            return $query->where($column, $requestedFacilityId);
        }

        return $query;
    }
}
