<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'username', 'name', 'email', 'password', 'role',
        'facility_id', 'department_id', 'phone_encrypted',
        'active', 'last_login_at', 'password_changed_at', 'inactivity_timeout',
    ];

    protected $hidden = ['password', 'remember_token', 'phone_encrypted'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function hasRole(string|array $roles): bool
    {
        $roles = (array) $roles;
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'system_admin';
    }

    public function isManager(): bool
    {
        return in_array($this->role, ['system_admin', 'clinic_manager'], true);
    }

    public function getPhone(): ?string
    {
        if ($this->phone_encrypted === null) {
            return null;
        }
        return decrypt($this->phone_encrypted);
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
