<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewImage extends Model
{
    protected $fillable = ['review_id', 'file_path', 'file_name', 'checksum', 'sort_order'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(VisitReview::class, 'review_id');
    }
}
