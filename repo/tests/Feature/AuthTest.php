<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'password' => Hash::make('Password123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'testuser',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user', 'captcha_required']);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create(['username' => 'testuser']);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'testuser',
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }

    public function test_login_records_failed_attempt(): void
    {
        User::factory()->create(['username' => 'testuser']);

        $this->postJson('/api/auth/login', [
            'username' => 'testuser',
            'password' => 'wrong_password',
        ]);

        $this->assertDatabaseHas('login_attempts', [
            'username' => 'testuser',
            'success'  => false,
        ]);
    }

    public function test_login_blocked_after_max_attempts(): void
    {
        $ip = '127.0.0.1';
        $maxAttempts = (int) config('vetops.max_login_attempts', 10);
        $windowMinutes = (int) config('vetops.login_window_minutes', 10);

        for ($i = 0; $i < $maxAttempts; $i++) {
            LoginAttempt::create([
                'username'     => 'baduser',
                'ip_address'   => $ip,
                'throttle_key' => $ip,
                'success'      => false,
                'attempted_at' => now(),
            ]);
        }

        $response = $this->postJson('/api/auth/login', [
            'username' => 'baduser',
            'password' => 'anypassword',
        ]);

        $response->assertStatus(422);
    }

    public function test_captcha_required_after_threshold_failures(): void
    {
        $ip = '127.0.0.1';
        $captchaAfter = (int) config('vetops.captcha_after', 5);

        for ($i = 0; $i < $captchaAfter; $i++) {
            LoginAttempt::create([
                'username'     => 'baduser',
                'ip_address'   => $ip,
                'throttle_key' => $ip,
                'success'      => false,
                'attempted_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/auth/captcha-status');
        $response->assertStatus(200)
            ->assertJson(['captcha_required' => true]);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/auth/logout');

        $response->assertStatus(200);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_get_authenticated_user(): void
    {
        $user = $this->actingAsAdmin();

        $response = $this->getJson('/api/auth/me');
        $response->assertStatus(200)->assertJsonPath('user.id', $user->id);
    }

    public function test_change_password_requires_min_12_chars(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/auth/change-password', [
            'current_password'      => 'Password123!',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_change_password_success(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
        ]);
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/auth/change-password', [
            'current_password'      => 'OldPassword123!',
            'password'              => 'NewPassword456!@#',
            'password_confirmation' => 'NewPassword456!@#',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('NewPassword456!@#', $user->fresh()->password));
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'username' => 'inactive',
            'password' => Hash::make('Password123!'),
            'active'   => false,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'inactive',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(422);
    }

    public function test_password_must_be_at_least_12_characters(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/users', [
            'username'  => 'newuser',
            'name'      => 'New User',
            'password'  => 'Short1!',
            'password_confirmation' => 'Short1!',
            'role'      => 'inventory_clerk',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_change_password_fails_with_wrong_current(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CorrectPass123!')]);
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/auth/change-password', [
            'current_password'      => 'WrongPass456!',
            'password'              => 'BrandNewPass789!',
            'password_confirmation' => 'BrandNewPass789!',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['current_password']);
    }

    public function test_login_blocked_without_captcha_token_when_required(): void
    {
        $captchaAfter = (int) config('vetops.captcha_after', 5);
        $ip = '127.0.0.1';

        for ($i = 0; $i < $captchaAfter; $i++) {
            LoginAttempt::create([
                'username'     => 'baduser',
                'ip_address'   => $ip,
                'throttle_key' => $ip,
                'success'      => false,
                'attempted_at' => now(),
            ]);
        }

        User::factory()->create([
            'username' => 'realuser',
            'password' => Hash::make('GoodPass123!'),
        ]);

        // Login without captcha_token when captcha is required must fail.
        $response = $this->postJson('/api/auth/login', [
            'username' => 'realuser',
            'password' => 'GoodPass123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['captcha_token']);
    }

    public function test_captcha_challenge_is_returned_when_required(): void
    {
        $captchaAfter = (int) config('vetops.captcha_after', 5);
        $ip = '127.0.0.1';

        for ($i = 0; $i < $captchaAfter; $i++) {
            LoginAttempt::create([
                'username'     => 'baduser',
                'ip_address'   => $ip,
                'throttle_key' => $ip,
                'success'      => false,
                'attempted_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/auth/captcha-status');

        $response->assertStatus(200)
            ->assertJsonStructure(['captcha_required', 'challenge'])
            ->assertJsonPath('captcha_required', true);
    }

    public function test_login_succeeds_with_correct_captcha_token(): void
    {
        $captchaAfter = (int) config('vetops.captcha_after', 5);
        $ip = '127.0.0.1';

        for ($i = 0; $i < $captchaAfter; $i++) {
            LoginAttempt::create([
                'username'     => 'baduser',
                'ip_address'   => $ip,
                'throttle_key' => $ip,
                'success'      => false,
                'attempted_at' => now(),
            ]);
        }

        User::factory()->create([
            'username' => 'realuser',
            'password' => Hash::make('GoodPass123!'),
        ]);

        // Fetch the challenge so the cache entry is created.
        $statusResponse = $this->getJson('/api/auth/captcha-status');
        $challenge = $statusResponse->json('challenge'); // e.g. "3 + 7"

        // Solve the math problem.
        preg_match('/(\d+)\s*\+\s*(\d+)/', $challenge, $m);
        $answer = (string)((int)$m[1] + (int)$m[2]);

        $response = $this->postJson('/api/auth/login', [
            'username'      => 'realuser',
            'password'      => 'GoodPass123!',
            'captcha_token' => $answer,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('captcha_required', true)
            ->assertJsonStructure(['token', 'user', 'captcha_required']);
    }

    public function test_login_blocked_with_wrong_captcha_token(): void
    {
        $captchaAfter = (int) config('vetops.captcha_after', 5);
        $ip = '127.0.0.1';

        for ($i = 0; $i < $captchaAfter; $i++) {
            LoginAttempt::create([
                'username'     => 'baduser',
                'ip_address'   => $ip,
                'throttle_key' => $ip,
                'success'      => false,
                'attempted_at' => now(),
            ]);
        }

        // Seed the challenge in cache by calling captchaStatus.
        $this->getJson('/api/auth/captcha-status');

        User::factory()->create([
            'username' => 'realuser',
            'password' => Hash::make('GoodPass123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username'      => 'realuser',
            'password'      => 'GoodPass123!',
            'captcha_token' => '999', // deliberately wrong
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['captcha_token']);
    }

    /* ---- per-workstation throttle tests ---- */

    public function test_device_id_is_stored_as_throttle_key_on_login_attempt(): void
    {
        User::factory()->create(['username' => 'testuser2']);

        $this->postJson('/api/auth/login', [
            'username' => 'testuser2',
            'password' => 'wrong',
        ], ['X-Device-ID' => 'ws-UNIT-001']);

        $this->assertDatabaseHas('login_attempts', [
            'username'     => 'testuser2',
            'device_id'    => 'ws-UNIT-001',
            'throttle_key' => 'ws-UNIT-001',
            'success'      => false,
        ]);
    }

    public function test_throttle_keyed_by_device_id_not_ip(): void
    {
        $captchaAfter = (int) config('vetops.captcha_after', 5);

        // Seed failures for a specific workstation — stored under its device key.
        for ($i = 0; $i < $captchaAfter; $i++) {
            LoginAttempt::create([
                'username'     => 'baduser',
                'ip_address'   => '10.0.0.50',
                'throttle_key' => 'ws-LOCKED-001',
                'success'      => false,
                'attempted_at' => now(),
            ]);
        }

        // Same IP, different device ID — must NOT be throttled.
        $response = $this->getJson('/api/auth/captcha-status', ['X-Device-ID' => 'ws-CLEAN-002']);
        $response->assertStatus(200)->assertJsonPath('captcha_required', false);
    }

    public function test_same_device_id_from_different_ips_shares_throttle(): void
    {
        $captchaAfter = (int) config('vetops.captcha_after', 5);
        $deviceId = 'ws-SHARED-003';

        // Failures arrive from two different IPs but the same workstation ID.
        for ($i = 0; $i < $captchaAfter; $i++) {
            LoginAttempt::create([
                'username'     => 'baduser',
                'ip_address'   => $i % 2 === 0 ? '192.168.1.10' : '192.168.1.11',
                'device_id'    => $deviceId,
                'throttle_key' => $deviceId,
                'success'      => false,
                'attempted_at' => now(),
            ]);
        }

        // Captcha status must be required for that device ID.
        $response = $this->getJson('/api/auth/captcha-status', ['X-Device-ID' => $deviceId]);
        $response->assertStatus(200)->assertJsonPath('captcha_required', true);
    }

    public function test_ip_fallback_throttle_when_no_device_id(): void
    {
        $ip = '127.0.0.1';
        $captchaAfter = (int) config('vetops.captcha_after', 5);

        // Seed failures keyed by IP (no device ID).
        for ($i = 0; $i < $captchaAfter; $i++) {
            LoginAttempt::create([
                'username'     => 'baduser',
                'ip_address'   => $ip,
                'throttle_key' => $ip,
                'success'      => false,
                'attempted_at' => now(),
            ]);
        }

        // No X-Device-ID header — falls back to IP-based key.
        $response = $this->getJson('/api/auth/captcha-status');
        $response->assertStatus(200)->assertJsonPath('captcha_required', true);
    }

    public function test_me_endpoint_loads_facility_and_department(): void
    {
        $user = $this->actingAsAdmin();

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['user' => ['id', 'username', 'role', 'facility', 'department']]);
    }

    public function test_password_change_is_audited(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPass123456!')]);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/auth/change-password', [
            'current_password'      => 'OldPass123456!',
            'password'              => 'NewSecurePass9876!',
            'password_confirmation' => 'NewSecurePass9876!',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed']);
    }

    public function test_refresh_returns_401_without_session_cookie(): void
    {
        $response = $this->postJson('/api/auth/refresh');

        $response->assertStatus(401)
            ->assertJsonPath('message', 'No active session.');
    }

    public function test_refresh_restores_token_from_valid_session_cookie(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $token = $user->createToken('api-token')->plainTextToken;

        $response = $this->withRefreshCookie($token)
            ->postJson('/api/auth/refresh');

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user', 'requires_password_change'])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('token', $token);
    }

    public function test_refresh_rejects_invalid_session_cookie(): void
    {
        $response = $this->withRefreshCookie('not-a-valid-sanctum-token')
            ->postJson('/api/auth/refresh');

        $response->assertStatus(422);
    }

    public function test_refresh_rejects_inactive_user_cookie(): void
    {
        $user = User::factory()->create(['active' => false]);
        $token = $user->createToken('api-token')->plainTextToken;

        $response = $this->withRefreshCookie($token)
            ->postJson('/api/auth/refresh');

        $response->assertStatus(422);
    }
}
