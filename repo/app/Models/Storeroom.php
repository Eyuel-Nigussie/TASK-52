<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Storeroom extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['facility_id', 'name', 'code', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }

    public function stocktakeSessions(): HasMany
    {
        return $this->hasMany(StocktakeSession::class);
    }

    public function scopeActive($query): mixed
    {
        return $query->where('active', true);
    }
}
