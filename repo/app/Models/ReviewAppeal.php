<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewAppeal extends Model
{
    protected $fillable = [
        'review_id', 'raised_by', 'reason', 'status',
        'resolved_by', 'resolution_note',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(VisitReview::class, 'review_id');
    }

    public function raisedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }
}
