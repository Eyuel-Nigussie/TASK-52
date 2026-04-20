<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByFacility;
use App\Http\Controllers\Controller;
use App\Models\RentalAsset;
use App\Services\AuditService;
use App\Services\DataVersioningService;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RentalAssetController extends Controller
{
    use ScopesByFacility;

    public function __construct(
        private readonly AuditService $audit,
        private readonly FileStorageService $storage,
        private readonly DataVersioningService $versioning,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RentalAsset::class);

        $query = RentalAsset::query()
            ->with(['facility', 'activeTransaction'])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('category'), fn($q) => $q->where('category', $request->category))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($inner) use ($request) {
                    $inner->where('name', 'like', '%' . $request->search . '%')
                          ->orWhere('barcode', $request->search)
                          ->orWhere('qr_code', $request->search)
                          ->orWhere('serial_number', $request->search);
                });
            })
            ->orderBy('name');

        $this->applyFacilityScope($query, $request->user(), $request->integer('facility_id') ?: null);

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', RentalAsset::class);

        $data = $request->validate([
            'facility_id'      => 'required|exists:facilities,id',
            'name'             => 'required|string|max:255',
            'category'         => 'required|string|max:100',
            'manufacturer'     => 'nullable|string|max:100',
            'model_number'     => 'nullable|string|max:100',
            'serial_number'    => 'nullable|string|max:100|unique:rental_assets',
            'barcode'          => 'nullable|string|max:100|unique:rental_assets',
            'qr_code'          => 'nullable|string|max:200|unique:rental_assets',
            'replacement_cost' => 'required|numeric|min:0',
            'daily_rate'       => 'required|numeric|min:0',
            'weekly_rate'      => 'nullable|numeric|min:0',
            'specs'            => 'nullable|array',
            'notes'            => 'nullable|string',
        ]);

        $user = $request->user();
        if (!$user->isAdmin() && $user->facility_id !== null && (int) $data['facility_id'] !== $user->facility_id) {
            abort(403, 'Cannot create assets for another facility.');
        }

        $data['created_by'] = $user->id;
        $asset = new RentalAsset($data);
        $asset->deposit_amount = $asset->calculateDeposit();
        $asset->save();

        $this->versioning->record($asset, [], $user->id, 'Created via API');
        $this->audit->logModel('rental_asset.create', $asset);

        return response()->json($asset, 201);
    }

    public function show(RentalAsset $rentalAsset): JsonResponse
    {
        $this->authorize('view', $rentalAsset);

        $rentalAsset->load(['facility', 'activeTransaction', 'transactions' => fn($q) => $q->latest()->take(10)]);
        return response()->json($rentalAsset);
    }

    public function update(Request $request, RentalAsset $rentalAsset): JsonResponse
    {
        $this->authorize('update', $rentalAsset);

        $data = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'category'         => 'sometimes|string|max:100',
            'status'           => 'sometimes|in:available,in_maintenance,deactivated',
            'manufacturer'     => 'nullable|string|max:100',
            'model_number'     => 'nullable|string|max:100',
            'replacement_cost' => 'sometimes|numeric|min:0',
            'daily_rate'       => 'sometimes|numeric|min:0',
            'weekly_rate'      => 'nullable|numeric|min:0',
            'specs'            => 'nullable|array',
            'notes'            => 'nullable|string',
        ]);

        $old = $rentalAsset->toArray();
        $data['updated_by'] = $request->user()->id;
        $rentalAsset->update($data);

        if (isset($data['replacement_cost'])) {
            $rentalAsset->update(['deposit_amount' => $rentalAsset->calculateDeposit()]);
        }

        $this->versioning->record($rentalAsset, $old, $request->user()->id, 'Updated via API');
        $this->audit->logModel('rental_asset.update', $rentalAsset, $old, $rentalAsset->fresh()->toArray());

        return response()->json($rentalAsset->fresh());
    }

    public function uploadPhoto(Request $request, RentalAsset $rentalAsset): JsonResponse
    {
        $this->authorize('uploadPhoto', $rentalAsset);

        $request->validate([
            'photo' => 'required|image|max:' . (config('vetops.upload_max_mb', 20) * 1024),
        ]);

        $fileData = $this->storage->store($request->file('photo'), 'rental_assets/photos');
        $rentalAsset->update([
            'photo_path'     => $fileData['path'],
            'photo_checksum' => $fileData['checksum'],
        ]);

        return response()->json(['photo_url' => $this->storage->url($fileData['path'])]);
    }

    public function scanLookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RentalAsset::class);

        $request->validate([
            'code' => 'required|string',
        ]);

        $code = $request->code;
        $query = RentalAsset::where(function ($q) use ($code) {
            $q->where('barcode', $code)
              ->orWhere('qr_code', $code)
              ->orWhere('serial_number', $code);
        })->with(['facility', 'activeTransaction']);

        $this->applyFacilityScope($query, $request->user());

        $asset = $query->first();

        if (!$asset) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        return response()->json($asset);
    }

    public function destroy(RentalAsset $rentalAsset): JsonResponse
    {
        $this->authorize('delete', $rentalAsset);

        if ($rentalAsset->activeTransaction()->exists()) {
            return response()->json(['message' => 'Cannot delete an asset that is currently rented.'], 422);
        }

        $this->audit->logModel('rental_asset.delete', $rentalAsset);
        $rentalAsset->delete();

        return response()->json(['message' => 'Asset deleted.']);
    }
}
