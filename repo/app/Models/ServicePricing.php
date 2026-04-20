<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Facility-specific pricing override for a Service, with an effective-date
 * window. Allows discount events, regional pricing, and audit-clean revisions
 * without mutating historical values.
 */
class ServicePricing extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'service_id', 'facility_id',
        'base_price', 'currency',
        'effective_from', 'effective_to',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeEffectiveOn($query, \DateTimeInterface $moment)
    {
        $date = $moment->format('Y-m-d');
        return $query->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            });
    }
}
