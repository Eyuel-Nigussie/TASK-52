<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A catalog service (consultation, vaccination, surgery, boarding, etc.).
 * Services are cross-facility definitions; per-facility price overrides
 * live in service_pricings.
 */
class Service extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'external_key', 'name', 'category', 'code', 'description',
        'duration_minutes', 'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'duration_minutes' => 'integer',
        ];
    }

    public function pricings(): HasMany
    {
        return $this->hasMany(ServicePricing::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
