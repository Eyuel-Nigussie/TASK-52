<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'external_key', 'name', 'sku', 'category', 'unit_of_measure',
        'safety_stock_days', 'reorder_point', 'supplier_info', 'active',
    ];

    protected function casts(): array
    {
        return [
            'supplier_info' => 'array',
            'active' => 'boolean',
            'reorder_point' => 'decimal:3',
        ];
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'item_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StockLedger::class, 'item_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(OrderInventoryReservation::class, 'item_id');
    }

    public function scopeActive($query): mixed
    {
        return $query->where('active', true);
    }
}
