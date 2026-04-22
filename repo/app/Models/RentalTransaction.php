<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id', 'renter_type', 'renter_id', 'facility_id',
        'checked_out_at', 'expected_return_at', 'actual_return_at', 'status',
        'deposit_collected', 'fee_amount', 'fee_terms', 'notes',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'checked_out_at' => 'datetime',
            'expected_return_at' => 'datetime',
            'actual_return_at' => 'datetime',
            'deposit_collected' => 'decimal:2',
            'fee_amount' => 'decimal:2',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(RentalAsset::class, 'asset_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function isOverdue(): bool
    {
        if ($this->status !== 'active' && $this->status !== 'overdue') {
            return false;
        }
        $overdueThreshold = (int) config('vetops.overdue_hours', 2);
        return now()->isAfter($this->expected_return_at->copy()->addHours($overdueThreshold));
    }

    public function overdueMinutes(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        $overdueThreshold = (int) config('vetops.overdue_hours', 2);
        $now = now();
        $expected = $this->expected_return_at->copy();

        return (int) $expected->copy()->addHours($overdueThreshold)->diffInMinutes($now);
    }

    public function scopeActive($query): mixed
    {
        return $query->whereIn('status', ['active', 'overdue']);
    }

    public function scopeOverdue($query): mixed
    {
        $overdueThreshold = (int) config('vetops.overdue_hours', 2);
        return $query->where('status', 'active')
            ->where('expected_return_at', '<', now()->subHours($overdueThreshold));
    }
}
