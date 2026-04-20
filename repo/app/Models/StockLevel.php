<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends Model
{
    protected $fillable = [
        'item_id', 'storeroom_id', 'on_hand', 'reserved',
        'available_to_promise', 'last_stocktake_at', 'avg_daily_usage',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:3',
            'reserved' => 'decimal:3',
            'available_to_promise' => 'decimal:3',
            'avg_daily_usage' => 'decimal:3',
            'last_stocktake_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function storeroom(): BelongsTo
    {
        return $this->belongsTo(Storeroom::class);
    }

    public function isBelowSafetyStock(): bool
    {
        $safetyStock = (float) $this->avg_daily_usage * ($this->item->safety_stock_days ?? config('vetops.safety_stock_days', 14));
        return (float) $this->on_hand <= $safetyStock;
    }

    public function recalculateAtp(): void
    {
        $this->available_to_promise = max(0, (float) $this->on_hand - (float) $this->reserved);
        $this->save();
    }
}
