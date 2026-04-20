<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsvImport extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'entity_type', 'file_path', 'file_checksum',
        'status', 'total_rows', 'processed_rows', 'error_rows', 'errors',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
