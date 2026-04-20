<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'content_item_id', 'version', 'title', 'body', 'changed_by', 'change_note',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::updating(fn() => false);
        static::deleting(fn() => false);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
