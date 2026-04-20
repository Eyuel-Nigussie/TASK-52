<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StocktakeSession extends Model
{
    protected $fillable = [
        'storeroom_id', 'status', 'started_by', 'approved_by',
        'approval_reason', 'started_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function storeroom(): BelongsTo
    {
        return $this->belongsTo(Storeroom::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(StocktakeEntry::class, 'session_id');
    }

    public function hasPendingApprovals(): bool
    {
        return $this->entries()->where('requires_approval', true)->whereNull('approved_by')->exists();
    }
}
