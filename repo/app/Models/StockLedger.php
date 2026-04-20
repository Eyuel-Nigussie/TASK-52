<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLedger extends Model
{
    public $timestamps = false;
    public $incrementing = true;

    protected $table = 'stock_ledger';

    protected $fillable = [
        'item_id', 'storeroom_id', 'transaction_type', 'quantity', 'balance_after',
        'reference_type', 'reference_id', 'from_storeroom_id', 'to_storeroom_id',
        'unit_cost', 'notes', 'performed_by', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        // Immutable - prevent updates
        static::updating(fn() => false);
        static::deleting(fn() => false);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function storeroom(): BelongsTo
    {
        return $this->belongsTo(Storeroom::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
