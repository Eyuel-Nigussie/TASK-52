<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class RentalAsset extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'facility_id', 'external_key', 'name', 'category', 'manufacturer',
        'model_number', 'serial_number', 'barcode', 'qr_code', 'status',
        'replacement_cost', 'daily_rate', 'weekly_rate', 'deposit_amount',
        'photo_path', 'photo_checksum', 'specs', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'specs' => 'array',
            'replacement_cost' => 'decimal:2',
            'daily_rate' => 'decimal:2',
            'weekly_rate' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(RentalTransaction::class, 'asset_id');
    }

    public function activeTransaction(): HasOne
    {
        return $this->hasOne(RentalTransaction::class, 'asset_id')
            ->whereIn('status', ['active', 'overdue']);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    public function scopeAvailable($query): mixed
    {
        return $query->where('status', 'available');
    }

    public function scopeActive($query): mixed
    {
        return $query->where('status', '!=', 'deactivated');
    }

    public function calculateDeposit(): float
    {
        $deposit = (float) $this->replacement_cost * config('vetops.deposit_rate', 0.20);
        return max($deposit, config('vetops.deposit_min', 50.00));
    }
}
