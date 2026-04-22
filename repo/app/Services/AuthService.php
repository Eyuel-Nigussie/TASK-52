<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    public function __construct(private readonly AuditService $audit) {}

    public function attempt(string $username, string $password, string $ip, ?string $captchaToken = null, ?string $deviceId = null): array
    {
        $maxAttempts = (int) config('vetops.max_login_attempts', 10);
        $windowMinutes = (int) config('vetops.login_window_minutes', 10);
        $captchaAfter = (int) config('vetops.captcha_after', 5);

        // Key throttle by workstation ID so shared-IP clinic networks don't
        // lock out one terminal when another is under attack.
        $throttleKey = $deviceId ?? $ip;

        $recentFailures = LoginAttempt::recentFailures($throttleKey, $windowMinutes);
        $captchaRequired = $recentFailures >= $captchaAfter;

        if ($recentFailures >= $maxAttempts) {
            LoginAttempt::create([
                'username'         => $username,
                'ip_address'       => $ip,
                'device_id'        => $deviceId,
                'throttle_key'     => $throttleKey,
                'success'          => false,
                'captcha_required' => true,
                'attempted_at'     => now(),
            ]);
            throw ValidationException::withMessages([
                'username' => ['Too many login attempts. Please wait before trying again.'],
            ]);
        }

        if ($captchaRequired && !$this->validateCaptchaToken($throttleKey, $captchaToken)) {
            throw ValidationException::withMessages([
                'captcha_token' => ['Incorrect CAPTCHA answer. Please try again.'],
            ]);
        }

        $user = User::where('username', $username)->first();

        if (!$user || !Hash::check($password, $user->password) || !$user->active) {
            LoginAttempt::create([
                'username'         => $username,
                'ip_address'       => $ip,
                'device_id'        => $deviceId,
                'throttle_key'     => $throttleKey,
                'success'          => false,
                'captcha_required' => $captchaRequired,
                'attempted_at'     => now(),
            ]);
            $this->audit->logLogin($username, false, $ip);
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        LoginAttempt::create([
            'username'         => $username,
            'ip_address'       => $ip,
            'device_id'        => $deviceId,
            'throttle_key'     => $throttleKey,
            'success'          => true,
            'captcha_required' => false,
            'attempted_at'     => now(),
        ]);

        $user->update(['last_login_at' => now()]);
        $this->audit->logLogin($username, true, $ip);

        $token = $user->createToken('api-token')->plainTextToken;

        return [
            'user'                     => $user,
            'token'                    => $token,
            'captcha_required'         => $captchaRequired,
            'requires_password_change' => $user->password_changed_at === null,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
        $this->audit->log('auth.logout', null, null, null, ['user_id' => $user->id]);
    }

    public function refreshFromCookie(string $token): array
    {
        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken || !$accessToken->tokenable) {
            throw ValidationException::withMessages([
                'session' => ['Session is invalid or has expired.'],
            ]);
        }

        $user = $accessToken->tokenable;

        if (!$user->active) {
            throw ValidationException::withMessages([
                'session' => ['Account is inactive.'],
            ]);
        }

        // Honor the same inactivity window the InactivityTimeoutMiddleware
        // enforces on authenticated routes. Otherwise a stale cookie could
        // rehydrate an idle token that the protected-route middleware would
        // otherwise reject.
        $timeoutMinutes = $user->inactivity_timeout
            ?? (int) config('vetops.inactivity_timeout', 15);
        $cacheKey = "vetops.token_idle:{$accessToken->id}";
        $lastSeenIso = Cache::get($cacheKey);

        if ($lastSeenIso !== null) {
            $lastSeen = \Carbon\Carbon::parse($lastSeenIso);
            if ($lastSeen->diffInMinutes(now()) >= $timeoutMinutes) {
                $accessToken->delete();
                Cache::forget($cacheKey);
                throw ValidationException::withMessages([
                    'session' => ['Session expired due to inactivity.'],
                ]);
            }
        }

        // Refresh the idle checkpoint so a successful rehydrate counts as
        // activity for the middleware's next check.
        Cache::put($cacheKey, now()->toIso8601String(), now()->addMinutes($timeoutMinutes + 60));

        return [
            'user'                     => $user,
            'requires_password_change' => $user->password_changed_at === null,
        ];
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $this->validatePasswordStrength($newPassword);

        $old = ['password_changed_at' => $user->password_changed_at];
        $user->update([
            'password' => Hash::make($newPassword),
            'password_changed_at' => now(),
        ]);
        $this->audit->logModel('auth.password_changed', $user, $old, ['password_changed_at' => now()]);
    }

    public function validatePasswordStrength(string $password): void
    {
        if (strlen($password) < 12) {
            throw ValidationException::withMessages([
                'password' => ['Password must be at least 12 characters.'],
            ]);
        }
    }

    public function requiresCaptcha(string $throttleKey): bool
    {
        return LoginAttempt::requiresCaptcha(
            $throttleKey,
            (int) config('vetops.login_window_minutes', 10),
            (int) config('vetops.captcha_after', 5),
        );
    }

    public function getCaptchaChallenge(string $throttleKey): array
    {
        if (!$this->requiresCaptcha($throttleKey)) {
            return ['captcha_required' => false];
        }

        $cacheKey = "vetops.captcha:{$throttleKey}";
        $cached = Cache::get($cacheKey);

        if ($cached) {
            return ['captcha_required' => true, 'challenge' => $cached['challenge']];
        }

        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $challenge = "{$a} + {$b}";

        Cache::put($cacheKey, ['challenge' => $challenge, 'answer' => (string)($a + $b)], now()->addMinutes(10));

        return ['captcha_required' => true, 'challenge' => $challenge];
    }

    public function validateCaptchaToken(string $throttleKey, ?string $token): bool
    {
        if (!$token) {
            return false;
        }
        $cached = Cache::get("vetops.captcha:{$throttleKey}");
        return $cached !== null && $cached['answer'] === trim($token);
    }
}
