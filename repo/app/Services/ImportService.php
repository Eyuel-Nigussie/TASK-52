<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CsvImport;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\Patient;
use App\Models\RentalAsset;
use App\Models\Service;
use App\Models\ServicePricing;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use League\Csv\Reader;

class ImportService
{
    public function __construct(
        private readonly FileStorageService $storage,
        private readonly DataVersioningService $versioning,
        private readonly AuditService $audit,
        private readonly DeduplicationService $dedup,
    ) {}

    public function queueImport(UploadedFile $file, string $entityType, int $userId): CsvImport
    {
        $fileData = $this->storage->store($file, 'imports');

        return CsvImport::create([
            'user_id'       => $userId,
            'entity_type'   => $entityType,
            'file_path'     => $fileData['path'],
            'file_checksum' => $fileData['checksum'],
            'status'        => 'pending',
            'created_at'    => now(),
        ]);
    }

    public function process(CsvImport $import): CsvImport
    {
        $import->update(['status' => 'processing']);

        try {
            $fullPath = Storage::path($import->file_path);
            $csv = Reader::createFromPath($fullPath, 'r');
            $csv->setHeaderOffset(0);
            $records = iterator_to_array($csv->getRecords());

            $import->update(['total_rows' => count($records)]);

            $errors = [];
            $processedRows = 0;

            DB::transaction(function () use ($import, $records, &$errors, &$processedRows) {
                foreach ($records as $rowIndex => $record) {
                    $rowNum = $rowIndex + 2;
                    try {
                        $this->processRow($import->entity_type, $record, $import->user_id);
                        $processedRows++;
                    } catch (\Throwable $e) {
                        $errors[] = ['row' => $rowNum, 'error' => $e->getMessage(), 'data' => $record];
                    }
                    $import->update(['processed_rows' => $processedRows]);
                }
            });

            $import->update([
                'status'       => count($errors) === count($records) ? 'failed' : 'completed',
                'error_rows'   => count($errors),
                'errors'       => $errors,
                'completed_at' => now(),
            ]);

            $this->audit->log('csv_import.complete', CsvImport::class, $import->id, null, [
                'entity_type' => $import->entity_type,
                'total' => count($records),
                'errors' => count($errors),
            ]);
        } catch (\Throwable $e) {
            $import->update(['status' => 'failed', 'errors' => [['error' => $e->getMessage()]]]);
        }

        return $import->refresh();
    }

    private function processRow(string $entityType, array $row, int $userId): void
    {
        match ($entityType) {
            'facility'       => $this->importFacility($row, $userId),
            'department'     => $this->importDepartment($row, $userId),
            'inventory_item' => $this->importInventoryItem($row, $userId),
            'doctor'         => $this->importDoctor($row, $userId),
            'patient'        => $this->importPatient($row, $userId),
            'rental_asset'   => $this->importRentalAsset($row, $userId),
            'service'        => $this->importService($row, $userId),
            'service_pricing' => $this->importServicePricing($row, $userId),
            default          => throw new \InvalidArgumentException("Unknown entity type: {$entityType}"),
        };
    }

    private function importFacility(array $row, int $userId): void
    {
        $this->validateRow($row, [
            'external_key' => 'required|string|max:100',
            'name'         => 'required|string|max:255',
            'address'      => 'required|string',
            'city'         => 'required|string',
            'state'        => 'required|string|size:2',
            'zip'          => 'required|string|max:10',
        ]);

        $existing = Facility::where('external_key', $row['external_key'])->first();
        $data = [
            'name'       => $row['name'],
            'address'    => $row['address'],
            'city'       => $row['city'],
            'state'      => $row['state'],
            'zip'        => $row['zip'],
            'email'      => $row['email'] ?? null,
            'active'     => isset($row['active']) ? (bool) $row['active'] : true,
            'updated_by' => $userId,
        ];

        if ($existing) {
            $old = $existing->toArray();
            $existing->update($data);
            $this->versioning->record($existing, $old, $userId, 'CSV import update');
        } else {
            $data['external_key'] = $row['external_key'];
            $data['created_by'] = $userId;
            $facility = Facility::create($data);
            $this->versioning->record($facility, [], $userId, 'CSV import create');
        }
    }

    private function importDepartment(array $row, int $userId): void
    {
        $this->validateRow($row, [
            'external_key'  => 'required|string|max:100',
            'facility_key'  => 'required|string|max:100',
            'name'          => 'required|string|max:255',
        ]);

        $facility = Facility::where('external_key', $row['facility_key'])->firstOrFail();
        $existing = Department::where('facility_id', $facility->id)->where('external_key', $row['external_key'])->first();
        $data = ['name' => $row['name'], 'code' => $row['code'] ?? null, 'active' => isset($row['active']) ? (bool)$row['active'] : true];

        if ($existing) {
            $old = $existing->toArray();
            $existing->update($data);
            $this->versioning->record($existing, $old, $userId, 'CSV import update');
        } else {
            $model = Department::create(array_merge($data, ['facility_id' => $facility->id, 'external_key' => $row['external_key']]));
            $this->versioning->record($model, [], $userId, 'CSV import create');
        }
    }

    private function importInventoryItem(array $row, int $userId): void
    {
        $this->validateRow($row, [
            'external_key' => 'required|string|max:100',
            'name'         => 'required|string|max:255',
            'category'     => 'required|string|max:100',
        ]);

        $data = [
            'name'             => $row['name'],
            'sku'              => $row['sku'] ?? null,
            'category'         => $row['category'],
            'unit_of_measure'  => $row['unit_of_measure'] ?? 'unit',
            'safety_stock_days' => isset($row['safety_stock_days']) ? (int)$row['safety_stock_days'] : 14,
            'active'           => isset($row['active']) ? (bool)$row['active'] : true,
        ];

        $existing = InventoryItem::where('external_key', $row['external_key'])->first();
        if ($existing) {
            $old = $existing->toArray();
            $existing->update($data);
            $this->versioning->record($existing, $old, $userId, 'CSV import update');
        } else {
            $model = InventoryItem::create(array_merge(['external_key' => $row['external_key']], $data));
            $this->versioning->record($model, [], $userId, 'CSV import create');
        }
    }

    private function importDoctor(array $row, int $userId): void
    {
        $this->validateRow($row, [
            'external_key'  => 'required|string|max:100',
            'facility_key'  => 'required|string|max:100',
            'first_name'    => 'required|string|max:100',
            'last_name'     => 'required|string|max:100',
        ]);

        $facility = Facility::where('external_key', $row['facility_key'])->firstOrFail();
        $data = [
            'first_name' => $row['first_name'],
            'last_name'  => $row['last_name'],
            'specialty'  => $row['specialty'] ?? null,
            'email'      => $row['email'] ?? null,
            'active'     => isset($row['active']) ? (bool)$row['active'] : true,
        ];

        $existing = Doctor::where('facility_id', $facility->id)->where('external_key', $row['external_key'])->first();
        if ($existing) {
            $old = $existing->toArray();
            $existing->update($data);
            $this->versioning->record($existing, $old, $userId, 'CSV import update');
        } else {
            // Key-field near-duplicate check: delegate to DeduplicationService so
            // the same matching rules are reused by dedup candidates and imports.
            $candidates = Doctor::where('facility_id', $facility->id)
                ->get(['id', 'external_key', 'first_name', 'last_name'])
                ->map(fn($d) => $d->toArray())
                ->toArray();
            $keyMatch = $this->dedup->matchByKeyFields(
                ['first_name' => $row['first_name'], 'last_name' => $row['last_name']],
                $candidates,
                ['first_name', 'last_name'],
            );
            if ($keyMatch !== null) {
                throw new \InvalidArgumentException(
                    "Key-field duplicate: doctor '{$row['first_name']} {$row['last_name']}' already exists at " .
                    "this facility (ID {$keyMatch['id']}, external_key={$keyMatch['external_key']}). " .
                    "Submit a merge-request to resolve, or correct the external_key."
                );
            }
            $model = Doctor::create(array_merge($data, ['facility_id' => $facility->id, 'external_key' => $row['external_key']]));
            $this->versioning->record($model, [], $userId, 'CSV import create');
        }
    }

    private function importPatient(array $row, int $userId): void
    {
        $this->validateRow($row, [
            'external_key'  => 'required|string|max:100',
            'facility_key'  => 'required|string|max:100',
            'name'          => 'required|string|max:100',
        ]);

        $facility = Facility::where('external_key', $row['facility_key'])->firstOrFail();
        $data = [
            'name'       => $row['name'],
            'species'    => $row['species'] ?? null,
            'breed'      => $row['breed'] ?? null,
            'owner_name' => $row['owner_name'] ?? null,
            'owner_email' => $row['owner_email'] ?? null,
        ];

        $existing = Patient::where('facility_id', $facility->id)->where('external_key', $row['external_key'])->first();
        if ($existing) {
            $old = $existing->toArray();
            $existing->update($data);
            $this->versioning->record($existing, $old, $userId, 'CSV import update');
        } else {
            // Key-field near-duplicate check: same name + species at same facility suggests a duplicate.
            $keyMatch = Patient::where('facility_id', $facility->id)
                ->where('name', $row['name'])
                ->when(!empty($row['species']), fn($q) => $q->where('species', $row['species']))
                ->first();
            if ($keyMatch) {
                throw new \InvalidArgumentException(
                    "Key-field duplicate: patient '{$row['name']}' (species: " . ($row['species'] ?? 'n/a') . ") " .
                    "already exists at this facility (ID {$keyMatch->id}, external_key={$keyMatch->external_key}). " .
                    "Submit a merge-request to resolve, or correct the external_key."
                );
            }
            $model = Patient::create(array_merge($data, ['facility_id' => $facility->id, 'external_key' => $row['external_key']]));
            $this->versioning->record($model, [], $userId, 'CSV import create');
        }
    }

    private function importRentalAsset(array $row, int $userId): void
    {
        $this->validateRow($row, [
            'external_key' => 'required|string|max:100',
            'facility_key' => 'required|string|max:100',
            'name'         => 'required|string|max:255',
            'category'     => 'required|string|max:100',
        ]);

        $facility = Facility::where('external_key', $row['facility_key'])->firstOrFail();
        $replacementCost = isset($row['replacement_cost']) ? (float)$row['replacement_cost'] : 0.0;
        $depositRate = (float) config('vetops.deposit_rate', 0.20);
        $depositMin = (float) config('vetops.deposit_min', 50.00);
        $depositAmount = max($replacementCost * $depositRate, $depositMin);

        $data = [
            'name'             => $row['name'],
            'category'         => $row['category'],
            'manufacturer'     => $row['manufacturer'] ?? null,
            'model_number'     => $row['model_number'] ?? null,
            'serial_number'    => $row['serial_number'] ?? null,
            'barcode'          => $row['barcode'] ?? null,
            'status'           => $row['status'] ?? 'available',
            'replacement_cost' => $replacementCost,
            'daily_rate'       => isset($row['daily_rate']) ? (float)$row['daily_rate'] : 0,
            'weekly_rate'      => isset($row['weekly_rate']) ? (float)$row['weekly_rate'] : 0,
            'deposit_amount'   => $depositAmount,
        ];

        $existing = RentalAsset::where('facility_id', $facility->id)->where('external_key', $row['external_key'])->first();
        if ($existing) {
            $old = $existing->toArray();
            $existing->update($data);
            $this->versioning->record($existing, $old, $userId, 'CSV import update');
        } else {
            $model = RentalAsset::create(array_merge($data, ['facility_id' => $facility->id, 'external_key' => $row['external_key']]));
            $this->versioning->record($model, [], $userId, 'CSV import create');
        }
    }

    private function importService(array $row, int $userId): void
    {
        $this->validateRow($row, [
            'external_key' => 'required|string|max:100',
            'name'         => 'required|string|max:255',
            'category'     => 'required|string|max:100',
        ]);

        $data = [
            'name'             => $row['name'],
            'category'         => $row['category'],
            'code'             => $row['code'] ?? null,
            'description'      => $row['description'] ?? null,
            'duration_minutes' => isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : 30,
            'active'           => isset($row['active']) ? (bool) $row['active'] : true,
        ];

        $existing = Service::where('external_key', $row['external_key'])->first();
        if ($existing) {
            $old = $existing->toArray();
            $existing->update($data);
            $this->versioning->record($existing, $old, $userId, 'CSV import update');
        } else {
            // Key-field near-duplicate: delegate to DeduplicationService so the
            // same name+category rule is reused everywhere dedup is surfaced.
            $candidates = Service::all(['id', 'external_key', 'name', 'category'])
                ->map(fn($s) => $s->toArray())
                ->toArray();
            $keyMatch = $this->dedup->matchByKeyFields(
                ['name' => $row['name'], 'category' => $row['category']],
                $candidates,
                ['name', 'category'],
            );
            if ($keyMatch !== null) {
                throw new \InvalidArgumentException(
                    "Key-field duplicate: service '{$row['name']}' (category: {$row['category']}) already exists " .
                    "(ID {$keyMatch['id']}, external_key={$keyMatch['external_key']}). " .
                    "Submit a merge-request to resolve, or correct the external_key."
                );
            }
            $model = Service::create(array_merge(['external_key' => $row['external_key']], $data));
            $this->versioning->record($model, [], $userId, 'CSV import create');
        }
    }

    private function importServicePricing(array $row, int $userId): void
    {
        $this->validateRow($row, [
            'service_key'    => 'required|string|max:100',
            'facility_key'   => 'required|string|max:100',
            'base_price'     => 'required|numeric|min:0',
            'effective_from' => 'required|date',
        ]);

        $service = Service::where('external_key', $row['service_key'])->firstOrFail();
        $facility = Facility::where('external_key', $row['facility_key'])->firstOrFail();

        $data = [
            'service_id'     => $service->id,
            'facility_id'    => $facility->id,
            'base_price'     => (float) $row['base_price'],
            'currency'       => $row['currency'] ?? 'USD',
            'effective_from' => $row['effective_from'],
            'effective_to'   => $row['effective_to'] ?? null,
            'active'         => isset($row['active']) ? (bool) $row['active'] : true,
        ];

        $existing = ServicePricing::where('service_id', $service->id)
            ->where('facility_id', $facility->id)
            ->where('effective_from', $row['effective_from'])
            ->first();

        if ($existing) {
            $old = $existing->toArray();
            $existing->update($data);
            $this->versioning->record($existing, $old, $userId, 'CSV import update');
        } else {
            $model = ServicePricing::create($data);
            $this->versioning->record($model, [], $userId, 'CSV import create');
        }
    }

    private function validateRow(array $row, array $rules): void
    {
        $validator = Validator::make($row, $rules);
        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }
    }

    public function export(string $entityType, array $filters = []): string
    {
        [$headers, $rows] = match ($entityType) {
            'facility' => [
                ['external_key', 'name', 'address', 'city', 'state', 'zip', 'email', 'active'],
                Facility::when(isset($filters['facility_id']), fn($q) => $q->where('id', $filters['facility_id']))->get()->map(fn($f) => [$f->external_key, $f->name, $f->address, $f->city, $f->state, $f->zip, $f->email, $f->active ? '1' : '0'])->toArray(),
            ],
            'inventory_item' => [
                ['external_key', 'name', 'sku', 'category', 'unit_of_measure', 'safety_stock_days', 'active'],
                InventoryItem::all()->map(fn($i) => [$i->external_key, $i->name, $i->sku, $i->category, $i->unit_of_measure, $i->safety_stock_days, $i->active ? '1' : '0'])->toArray(),
            ],
            'doctor' => [
                ['external_key', 'facility_key', 'first_name', 'last_name', 'specialty', 'email', 'active'],
                Doctor::with('facility')->get()->map(fn($d) => [$d->external_key, $d->facility?->external_key, $d->first_name, $d->last_name, $d->specialty, $d->email, $d->active ? '1' : '0'])->toArray(),
            ],
            'patient' => [
                ['external_key', 'facility_key', 'name', 'species', 'breed', 'owner_name', 'owner_email', 'active'],
                Patient::with('facility')->get()->map(fn($p) => [$p->external_key, $p->facility?->external_key, $p->name, $p->species, $p->breed, $p->owner_name, $p->owner_email, $p->active ? '1' : '0'])->toArray(),
            ],
            'rental_asset' => [
                ['external_key', 'facility_key', 'name', 'category', 'manufacturer', 'model_number', 'serial_number', 'barcode', 'status', 'replacement_cost', 'daily_rate', 'weekly_rate'],
                RentalAsset::with('facility')->get()->map(fn($a) => [$a->external_key, $a->facility?->external_key, $a->name, $a->category, $a->manufacturer, $a->model_number, $a->serial_number, $a->barcode, $a->status, $a->replacement_cost, $a->daily_rate, $a->weekly_rate])->toArray(),
            ],
            'department' => [
                ['external_key', 'facility_key', 'name', 'code', 'active'],
                Department::with('facility')->get()->map(fn($d) => [$d->external_key, $d->facility?->external_key, $d->name, $d->code, $d->active ? '1' : '0'])->toArray(),
            ],
            'service' => [
                ['external_key', 'name', 'category', 'code', 'description', 'duration_minutes', 'active'],
                Service::all()->map(fn($s) => [$s->external_key, $s->name, $s->category, $s->code, $s->description, $s->duration_minutes, $s->active ? '1' : '0'])->toArray(),
            ],
            'service_pricing' => [
                ['service_key', 'facility_key', 'base_price', 'currency', 'effective_from', 'effective_to', 'active'],
                ServicePricing::with(['service', 'facility'])->get()->map(fn($p) => [
                    $p->service?->external_key, $p->facility?->external_key, $p->base_price, $p->currency,
                    optional($p->effective_from)->toDateString(), optional($p->effective_to)->toDateString(),
                    $p->active ? '1' : '0',
                ])->toArray(),
            ],
            default => throw new \InvalidArgumentException("Export not supported for: {$entityType}"),
        };

        $output = implode(',', $headers) . "\n";
        foreach ($rows as $row) {
            $output .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\n";
        }
        return $output;
    }
}
