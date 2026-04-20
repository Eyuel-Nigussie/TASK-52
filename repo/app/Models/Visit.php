<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Visit extends Model
{
    use HasFactory;

    protected $fillable = [
        'facility_id', 'patient_id', 'doctor_id', 'service_order_id',
        'visit_date', 'status',
    ];

    protected function casts(): array
    {
        return ['visit_date' => 'date'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(VisitReview::class);
    }

    public function scopeCompleted($query): mixed
    {
        return $query->where('status', 'completed');
    }
}
