<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Doctor;
use App\Models\MergeRequest;
use App\Models\Patient;
use App\Models\RentalTransaction;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServicePricing;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitReview;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Executes entity-resolution merges approved by managers.
 *
 * A merge takes a source record (the duplicate) and folds it into a target
 * record (the surviving canonical record). We:
 *   1) relink every foreign key pointing at the source so it points at the
 *      target instead (provenance preserving — history rows are not deleted);
 *   2) snapshot both records via DataVersioningService for audit reversal;
 *   3) record an `entity.merge` audit entry with the resolution rules payload;
 *   4) soft-delete the source so it remains recoverable but is no longer a
 *      distinct identity.
 *
 * Why not hard-delete the source? Soft-delete plus the immutable audit log +
 * DataVersion snapshot preserves the forensic trail the prompt requires.
 * A manager can un-merge by restoring the source and reassigning foreign keys
 * from the recorded snapshot.
 */
class MergeService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DataVersioningService $versioning,
    ) {}

    /**
     * Supported entity-type keys and the class + relink map for each.
     *
     * Relink map: referencing model => foreign key column to rewrite.
     */
    private const SUPPORTED = [
        'patient' => [
            'class' => Patient::class,
            'relink' => [
                Visit::class => 'patient_id',
                ServiceOrder::class => 'patient_id',
            ],
        ],
        'doctor' => [
            'class' => Doctor::class,
            'relink' => [
                Visit::class => 'doctor_id',
                VisitReview::class => 'doctor_id',
                ServiceOrder::class => 'doctor_id',
            ],
        ],
        'rental_asset' => [
            'class' => \App\Models\RentalAsset::class,
            'relink' => [
                RentalTransaction::class => 'asset_id',
            ],
        ],
        'service' => [
            'class' => Service::class,
            'relink' => [
                ServicePricing::class => 'service_id',
            ],
        ],
    ];

    public function execute(MergeRequest $request, User $approver): MergeRequest
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['Merge request is not pending.'],
            ]);
        }

        $type = $request->entity_type;
        if (!array_key_exists($type, self::SUPPORTED)) {
            throw ValidationException::withMessages([
                'entity_type' => ["Merge not supported for entity type '{$type}'."],
            ]);
        }

        $spec = self::SUPPORTED[$type];
        $modelClass = $spec['class'];

        if ($request->source_id === $request->target_id) {
            throw ValidationException::withMessages([
                'source_id' => ['source_id and target_id must differ.'],
            ]);
        }

        /** @var Model $source */
        $source = $modelClass::query()->findOrFail($request->source_id);
        /** @var Model $target */
        $target = $modelClass::query()->findOrFail($request->target_id);

        // Same-facility guard — merging across clinics would silently move
        // patients/doctors out of their parent facility.
        if (property_exists($source, 'facility_id')
            || isset($source->facility_id) || isset($target->facility_id)) {
            if (($source->facility_id ?? null) !== ($target->facility_id ?? null)) {
                throw ValidationException::withMessages([
                    'target_id' => ['source and target must belong to the same facility.'],
                ]);
            }
        }

        // Approver guard — the approving user must either be a system_admin
        // or share the facility of the merge-request target. This closes the
        // bypass where a manager from clinic A could approve a merge whose
        // source and target live in clinic B.
        if (!$approver->isAdmin()) {
            $targetFacility = $source->facility_id ?? $target->facility_id ?? $request->facility_id;
            if ($approver->facility_id !== null && $targetFacility !== null && $approver->facility_id !== $targetFacility) {
                throw ValidationException::withMessages([
                    'facility_id' => ['Approver is not assigned to the facility of this merge request.'],
                ]);
            }
        }

        return DB::transaction(function () use ($request, $approver, $spec, $source, $target, $type) {
            // Snapshot for reversal.
            $this->versioning->record($source, $source->toArray(), $approver->id, "Pre-merge snapshot (source of merge #{$request->id})");
            $this->versioning->record($target, $target->toArray(), $approver->id, "Pre-merge snapshot (target of merge #{$request->id})");

            $relinked = [];
            foreach ($spec['relink'] as $refClass => $foreignKey) {
                $count = $refClass::where($foreignKey, $source->getKey())
                    ->update([$foreignKey => $target->getKey()]);
                $relinked[$refClass] = $count;
            }

            // Audit with provenance-rich payload.
            $this->audit->logModel('entity.merge', $target, null, [
                'merge_request_id' => $request->id,
                'entity_type'      => $type,
                'source_id'        => $source->getKey(),
                'target_id'        => $target->getKey(),
                'resolution_rules' => $request->resolution_rules,
                'relinked_counts'  => $relinked,
            ]);

            // Soft-delete the source. SoftDeletes trait is mandatory on supported models.
            if (method_exists($source, 'delete')) {
                $source->delete();
            }

            $request->update([
                'status'      => 'approved',
                'resolved_by' => $approver->id,
            ]);

            return $request->fresh();
        });
    }

    public static function isSupported(string $entityType): bool
    {
        return array_key_exists($entityType, self::SUPPORTED);
    }
}
