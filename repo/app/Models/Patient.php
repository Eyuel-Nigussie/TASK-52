<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'facility_id', 'external_key', 'name', 'species', 'breed',
        'owner_name', 'owner_phone_encrypted', 'owner_email', 'active',
    ];

    protected $hidden = ['owner_phone_encrypted'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function getOwnerPhone(): ?string
    {
        return $this->owner_phone_encrypted ? decrypt($this->owner_phone_encrypted) : null;
    }

    public function getMaskedOwnerPhone(): ?string
    {
        $phone = $this->getOwnerPhone();
        if ($phone === null) {
            return null;
        }
        if (preg_match('/\(?(\d{3})\)?[\s\-]?\d{3}[\s\-]?(\d{4})/', $phone, $m)) {
            return "({$m[1]}) ***-{$m[2]}";
        }
        return '***-***-****';
    }

    public function scopeActive($query): mixed
    {
        return $query->where('active', true);
    }
}
