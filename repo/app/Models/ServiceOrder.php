<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'facility_id', 'patient_id', 'doctor_id', 'status',
        'reservation_strategy', 'created_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(OrderInventoryReservation::class);
    }

    public function scopeOpen($query): mixed
    {
        return $query->where('status', 'open');
    }
}
