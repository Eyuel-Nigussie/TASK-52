<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entity_type', 'entity_id', 'version', 'data',
        'changed_by', 'changed_at', 'change_summary',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::updating(fn() => false);
        static::deleting(fn() => false);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
