<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Facility extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'external_key', 'name', 'address', 'city', 'state', 'zip',
        'phone_encrypted', 'email', 'business_hours', 'active',
        'created_by', 'updated_by',
    ];

    protected $hidden = ['phone_encrypted'];

    protected function casts(): array
    {
        return [
            'business_hours' => 'array',
            'active' => 'boolean',
        ];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function storerooms(): HasMany
    {
        return $this->hasMany(Storeroom::class);
    }

    public function rentalAssets(): HasMany
    {
        return $this->hasMany(RentalAsset::class);
    }

    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class);
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
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
