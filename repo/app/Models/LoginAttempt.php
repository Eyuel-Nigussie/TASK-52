<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = ['username', 'ip_address', 'device_id', 'throttle_key', 'success', 'captcha_required', 'attempted_at'];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'captcha_required' => 'boolean',
            'attempted_at' => 'datetime',
        ];
    }

    public static function recentFailures(string $throttleKey, int $windowMinutes = 10): int
    {
        return static::where('throttle_key', $throttleKey)
            ->where('success', false)
            ->where('attempted_at', '>=', now()->subMinutes($windowMinutes))
            ->count();
    }

    public static function requiresCaptcha(string $throttleKey, int $windowMinutes = 10, int $threshold = 5): bool
    {
        return static::recentFailures($throttleKey, $windowMinutes) >= $threshold;
    }
}
