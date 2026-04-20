<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VisitReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'visit_id', 'facility_id', 'doctor_id', 'rating', 'tags', 'body',
        'status', 'submitted_at', 'submitted_by_name',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'submitted_at' => 'datetime',
            'rating' => 'integer',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ReviewImage::class, 'review_id')->orderBy('sort_order');
    }

    public function response(): HasOne
    {
        return $this->hasOne(ReviewResponse::class, 'review_id');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(ReviewAppeal::class, 'review_id');
    }

    public function isNegative(): bool
    {
        return $this->rating <= 2;
    }

    public function scopePublished($query): mixed
    {
        return $query->where('status', 'published');
    }
}
