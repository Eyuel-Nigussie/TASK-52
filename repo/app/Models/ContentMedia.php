<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentMedia extends Model
{
    protected $fillable = [
        'content_item_id', 'file_path', 'file_name', 'mime_type',
        'file_size', 'checksum', 'sort_order',
    ];

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }
}
