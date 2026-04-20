<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderInventoryReservation extends Model
{
    protected $fillable = [
        'service_order_id', 'item_id', 'storeroom_id',
        'quantity_reserved', 'quantity_deducted', 'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity_reserved' => 'decimal:3',
            'quantity_deducted' => 'decimal:3',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function storeroom(): BelongsTo
    {
        return $this->belongsTo(Storeroom::class);
    }
}
