<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RentalService
{
    public function __construct(private readonly AuditService $audit) {}

    public function checkout(
        RentalAsset $asset,
        string $renterType,
        int $renterId,
        int $facilityId,
        Carbon $expectedReturnAt,
        int $createdBy,
        ?string $notes = null,
        ?string $feeTerms = null
    ): RentalTransaction {
        return DB::transaction(function () use (
            $asset, $renterType, $renterId, $facilityId,
            $expectedReturnAt, $createdBy, $notes, $feeTerms
        ) {
            // Re-fetch with a pessimistic write lock so concurrent checkout
            // requests queue behind this transaction instead of both passing
            // the availability check before either commits.
            $lockedAsset = RentalAsset::lockForUpdate()->findOrFail($asset->id);

            if (!$lockedAsset->isAvailable()) {
                throw ValidationException::withMessages([
                    'asset_id' => ['Asset is not available for rental.'],
                ]);
            }

            $hasActiveBooking = RentalTransaction::where('asset_id', $lockedAsset->id)
                ->whereIn('status', ['active', 'overdue'])
                ->lockForUpdate()
                ->exists();

            if ($hasActiveBooking) {
                throw ValidationException::withMessages([
                    'asset_id' => ['Asset is already rented. Double-booking is not allowed.'],
                ]);
            }

            $deposit = $lockedAsset->calculateDeposit();

            $transaction = RentalTransaction::create([
                'asset_id'           => $lockedAsset->id,
                'renter_type'        => $renterType,
                'renter_id'          => $renterId,
                'facility_id'        => $facilityId,
                'checked_out_at'     => now(),
                'expected_return_at' => $expectedReturnAt,
                'status'             => 'active',
                'deposit_collected'  => $deposit,
                'fee_amount'         => 0,
                'fee_terms'          => $feeTerms,
                'notes'              => $notes,
                'created_by'         => $createdBy,
            ]);

            $lockedAsset->update(['status' => 'rented', 'updated_by' => $createdBy]);
            $this->audit->logModel('rental.checkout', $lockedAsset, ['status' => 'available'], ['status' => 'rented']);

            return $transaction;
        });
    }

    public function return(RentalTransaction $transaction, int $updatedBy, ?string $notes = null): RentalTransaction
    {
        if ($transaction->status === 'returned') {
            throw ValidationException::withMessages([
                'transaction_id' => ['Asset has already been returned.'],
            ]);
        }

        return DB::transaction(function () use ($transaction, $updatedBy, $notes) {
            $fee = $this->calculateFee($transaction);

            $transaction->update([
                'status'           => 'returned',
                'actual_return_at' => now(),
                'fee_amount'       => $fee,
                'notes'            => $notes ?? $transaction->notes,
                'updated_by'       => $updatedBy,
            ]);

            $transaction->asset->update(['status' => 'available', 'updated_by' => $updatedBy]);
            $this->audit->logModel('rental.return', $transaction->asset, ['status' => 'rented'], ['status' => 'available']);

            return $transaction->refresh();
        });
    }

    public function markOverdue(): int
    {
        $overdueHours = (int) config('vetops.overdue_hours', 2);
        $threshold = now()->subHours($overdueHours);

        return RentalTransaction::where('status', 'active')
            ->where('expected_return_at', '<', $threshold)
            ->update(['status' => 'overdue']);
    }

    public function calculateFee(RentalTransaction $transaction): float
    {
        $asset = $transaction->asset;
        $returnTime = $transaction->actual_return_at ?? now();
        $daysRented = max(1, (int) ceil($transaction->checked_out_at->diffInHours($returnTime) / 24));

        if ($daysRented >= 7 && $asset->weekly_rate > 0) {
            $weeks = floor($daysRented / 7);
            $remainingDays = $daysRented % 7;
            return ($weeks * (float) $asset->weekly_rate) + ($remainingDays * (float) $asset->daily_rate);
        }

        return $daysRented * (float) $asset->daily_rate;
    }

    public function getOverdueTransactions(): \Illuminate\Database\Eloquent\Collection
    {
        return RentalTransaction::with(['asset', 'facility'])
            ->whereIn('status', ['active', 'overdue'])
            ->where('expected_return_at', '<', now()->subHours((int) config('vetops.overdue_hours', 2)))
            ->orderBy('expected_return_at')
            ->get();
    }
}
