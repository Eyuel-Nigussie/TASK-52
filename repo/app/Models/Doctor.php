<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Doctor extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'facility_id', 'external_key', 'first_name', 'last_name',
        'specialty', 'license_number', 'phone_encrypted', 'email', 'active',
    ];

    protected $hidden = ['phone_encrypted'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(VisitReview::class, 'doctor_id');
    }

    public function getFullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getPhone(): ?string
    {
        return $this->phone_encrypted ? decrypt($this->phone_encrypted) : null;
    }

    public function getMaskedPhone(): ?string
    {
        $phone = $this->getPhone();
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
