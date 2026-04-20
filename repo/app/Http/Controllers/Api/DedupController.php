<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\RentalAsset;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Surfaces potential duplicate records so managers can decide whether to
 * create a merge-request. Detection runs by key-field grouping: records
 * sharing the same discriminating fields (name+facility, name+category, …)
 * but with different IDs are candidate duplicates.
 *
 * This endpoint is read-only. Actual resolution happens via merge-requests.
 */
class DedupController extends Controller
{
    private const SUPPORTED_TYPES = ['doctor', 'patient', 'service', 'rental_asset'];

    public function candidates(Request $request): JsonResponse
    {
        $request->validate([
            'entity_type' => 'required|string|in:' . implode(',', self::SUPPORTED_TYPES),
            'facility_id' => 'nullable|integer|min:1',
        ]);

        $entityType = $request->entity_type;
        $user = $request->user();
        $requestedFacilityId = $request->integer('facility_id') ?: null;

        // Tenant isolation: non-admins are pinned to their own facility
        // regardless of what facility_id they pass. Admins may query
        // any facility or all facilities (null).
        if ($user->isAdmin() || $user->facility_id === null) {
            $facilityId = $requestedFacilityId;
        } else {
            if ($requestedFacilityId !== null && $requestedFacilityId !== (int) $user->facility_id) {
                abort(403, 'Cannot query dedup candidates for another facility.');
            }
            $facilityId = (int) $user->facility_id;
        }

        $groups = match ($entityType) {
            'doctor'       => $this->doctorCandidates($facilityId),
            'patient'      => $this->patientCandidates($facilityId),
            'service'      => $this->serviceCandidates(),
            'rental_asset' => $this->rentalAssetCandidates($facilityId),
        };

        return response()->json([
            'entity_type' => $entityType,
            'groups'      => $groups,
            'total_groups' => count($groups),
        ]);
    }

    private function doctorCandidates(?int $facilityId): array
    {
        $query = Doctor::query()
            ->select('first_name', 'last_name', 'facility_id', DB::raw('COUNT(*) as cnt'), DB::raw('GROUP_CONCAT(id ORDER BY id) as ids'))
            ->groupBy('first_name', 'last_name', 'facility_id')
            ->having('cnt', '>', 1);

        if ($facilityId !== null) {
            $query->where('facility_id', $facilityId);
        }

        return $query->get()->map(function ($row) {
            $ids = explode(',', $row->ids);
            return [
                'key_fields' => [
                    'first_name'  => $row->first_name,
                    'last_name'   => $row->last_name,
                    'facility_id' => $row->facility_id,
                ],
                'records' => Doctor::whereIn('id', $ids)->get(['id', 'external_key', 'first_name', 'last_name', 'facility_id', 'email', 'specialty']),
            ];
        })->values()->all();
    }

    private function patientCandidates(?int $facilityId): array
    {
        $query = Patient::query()
            ->select('name', 'species', 'facility_id', DB::raw('COUNT(*) as cnt'), DB::raw('GROUP_CONCAT(id ORDER BY id) as ids'))
            ->groupBy('name', 'species', 'facility_id')
            ->having('cnt', '>', 1);

        if ($facilityId !== null) {
            $query->where('facility_id', $facilityId);
        }

        return $query->get()->map(function ($row) {
            $ids = explode(',', $row->ids);
            return [
                'key_fields' => [
                    'name'        => $row->name,
                    'species'     => $row->species,
                    'facility_id' => $row->facility_id,
                ],
                'records' => Patient::whereIn('id', $ids)->get(['id', 'external_key', 'name', 'species', 'breed', 'facility_id', 'owner_name']),
            ];
        })->values()->all();
    }

    private function serviceCandidates(): array
    {
        return Service::query()
            ->select('name', 'category', DB::raw('COUNT(*) as cnt'), DB::raw('GROUP_CONCAT(id ORDER BY id) as ids'))
            ->groupBy('name', 'category')
            ->having('cnt', '>', 1)
            ->get()
            ->map(function ($row) {
                $ids = explode(',', $row->ids);
                return [
                    'key_fields' => [
                        'name'     => $row->name,
                        'category' => $row->category,
                    ],
                    'records' => Service::whereIn('id', $ids)->get(['id', 'external_key', 'name', 'category', 'code', 'duration_minutes']),
                ];
            })->values()->all();
    }

    private function rentalAssetCandidates(?int $facilityId): array
    {
        $query = RentalAsset::query()
            ->select('name', 'category', 'facility_id', DB::raw('COUNT(*) as cnt'), DB::raw('GROUP_CONCAT(id ORDER BY id) as ids'))
            ->groupBy('name', 'category', 'facility_id')
            ->having('cnt', '>', 1);

        if ($facilityId !== null) {
            $query->where('facility_id', $facilityId);
        }

        return $query->get()->map(function ($row) {
            $ids = explode(',', $row->ids);
            return [
                'key_fields' => [
                    'name'        => $row->name,
                    'category'    => $row->category,
                    'facility_id' => $row->facility_id,
                ],
                'records' => RentalAsset::whereIn('id', $ids)->get(['id', 'external_key', 'name', 'category', 'facility_id', 'serial_number', 'barcode']),
            ];
        })->values()->all();
    }
}
