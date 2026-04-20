<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MergeRequest extends Model
{
    use HasFactory;
    protected $fillable = [
        'entity_type', 'facility_id', 'source_id', 'target_id',
        'conflict_data', 'resolution_rules', 'status',
        'requested_by', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'conflict_data' => 'array',
            'resolution_rules' => 'array',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
