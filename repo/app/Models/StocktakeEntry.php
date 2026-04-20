<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeEntry extends Model
{
    protected $fillable = [
        'session_id', 'item_id', 'system_quantity', 'counted_quantity',
        'variance_pct', 'requires_approval', 'approved_by', 'approval_reason',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:3',
            'counted_quantity' => 'decimal:3',
            'variance_pct' => 'decimal:4',
            'requires_approval' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StocktakeSession::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public static function calculateVariancePct(float $system, float $counted): float
    {
        if ($system == 0) {
            return $counted > 0 ? 100.0 : 0.0;
        }
        return abs(($counted - $system) / $system * 100);
    }

    public function requiresManagerApproval(): bool
    {
        $threshold = (float) config('vetops.stocktake_variance_pct', 5);
        return (float) $this->variance_pct > $threshold;
    }
}
