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
 *   - A non-admin user with facility_id = null is treated as an unassigned
 *     legacy account. The UserController now rejects creating/updating such
 *     accounts for non-admin roles, so this path only triggers for data
 *     created before the enforcement was added. We keep the legacy "see
 *     everything" behavior here to avoid silently emptying lists for those
 *     accounts; fresh deployments cannot land in this state.
 */
trait ScopesByFacility
{
    protected function applyFacilityScope(Builder $query, ?User $user, ?int $requestedFacilityId = null, string $column = 'facility_id'): Builder
    {
        if ($user !== null && !$user->isAdmin() && $user->facility_id !== null) {
            return $query->where($column, $user->facility_id);
        }

        if ($requestedFacilityId !== null) {
            return $query->where($column, $requestedFacilityId);
        }

        return $query;
    }
}
