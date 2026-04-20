<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DataVersion;
use Illuminate\Database\Eloquent\Model;

class DataVersioningService
{
    public function record(Model $model, array $oldData, int $changedBy, string $summary = ''): DataVersion
    {
        $lastVersion = DataVersion::where('entity_type', get_class($model))
            ->where('entity_id', $model->getKey())
            ->max('version') ?? 0;

        return DataVersion::create([
            'entity_type'    => get_class($model),
            'entity_id'      => $model->getKey(),
            'version'        => $lastVersion + 1,
            'data'           => $model->toArray(),
            'changed_by'     => $changedBy,
            'changed_at'     => now(),
            'change_summary' => $summary,
        ]);
    }

    public function getHistory(Model $model): \Illuminate\Database\Eloquent\Collection
    {
        return DataVersion::where('entity_type', get_class($model))
            ->where('entity_id', $model->getKey())
            ->orderBy('version', 'desc')
            ->get();
    }

    public function revert(Model $model, int $version, int $revertedBy): Model
    {
        $versionRecord = DataVersion::where('entity_type', get_class($model))
            ->where('entity_id', $model->getKey())
            ->where('version', $version)
            ->firstOrFail();

        $data = $versionRecord->data;
        unset($data['id'], $data['created_at'], $data['updated_at'], $data['deleted_at']);

        $model->update($data);
        $this->record($model, $model->toArray(), $revertedBy, "Reverted to version {$version}");

        return $model->refresh();
    }
}
