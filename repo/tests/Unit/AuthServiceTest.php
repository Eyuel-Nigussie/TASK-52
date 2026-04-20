<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unit-level coverage for AuthService's login/captcha/attempt/password flow.
 *
 * These exercise the service directly so we can assert edge behavior (token
 * lifecycle, captcha challenge lifecycle, password-strength rule) without
 * going through the HTTP layer.
 */
class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = app(AuthService::class);
    }

    public function test_attempt_returns_token_and_user_for_valid_credentials(): void
    {
        $user = User::factory()->create([
            'username' => 'unitcase',
            'password' => Hash::make('UnitPass12345!'),
        ]);

        $result = $this->auth->attempt('unitcase', 'UnitPass12345!', '10.0.0.1');

        $this->assertArrayHasKey('token', $result);
        $this->assertNotEmpty($result['token']);
        $this->assertEquals($user->id, $result['user']->id);
        $this->assertFalse($result['captcha_required']);
    }

    public function test_attempt_rejects_wrong_password_and_records_failure(): void
    {
        User::factory()->create([
            'username' => 'unitcase',
            'password' => Hash::make('CorrectPassword12!'),
        ]);

        try {
            $this->auth->attempt('unitcase', 'wrong', '10.0.0.1');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('username', $e->errors());
        }

        $this->assertEquals(1, LoginAttempt::where('username', 'unitcase')->where('success', false)->count());
    }

    public function test_attempt_rejects_inactive_user(): void
    {
        $this->expectException(ValidationException::class);

        User::factory()->create([
            'username' => 'sleeper',
            'password' => Hash::make('GoodPass123456!'),
            'active'   => false,
        ]);

        $this->auth->attempt('sleeper', 'GoodPass123456!', '10.0.0.2');
    }

    public function test_requires_captcha_toggles_after_threshold_failures(): void
    {
        $ip = '10.0.0.3';
        $threshold = (int) config('vetops.captcha_after', 5);

        $this->assertFalse($this->auth->requiresCaptcha($ip));

        for ($i = 0; $i < $threshold; $i++) {
            LoginAttempt::create([
                'username'     => 'baduser',
                'ip_address'   => $ip,
                'success'      => false,
                'attempted_at' => now(),
            ]);
        }

        $this->assertTrue($this->auth->requiresCaptcha($ip));
    }

    public function test_get_captcha_challenge_caches_answer_and_returns_question(): void
    {
        $ip = '10.0.0.4';
        $threshold = (int) config('vetops.captcha_after', 5);

        for ($i = 0; $i < $threshold; $i++) {
            LoginAttempt::create([
                'username'     => 'bad',
                'ip_address'   => $ip,
                'success'      => false,
                'attempted_at' => now(),
            ]);
        }

        $resp = $this->auth->getCaptchaChallenge($ip);

        $this->assertTrue($resp['captcha_required']);
        $this->assertArrayHasKey('challenge', $resp);
        $this->assertMatchesRegularExpression('/^\d+\s*\+\s*\d+$/', $resp['challenge']);
        $this->assertNotNull(Cache::get("vetops.captcha:{$ip}"));
    }

    public function test_get_captcha_challenge_returns_false_when_not_required(): void
    {
        $resp = $this->auth->getCaptchaChallenge('10.0.0.5');

        $this->assertFalse($resp['captcha_required']);
        $this->assertArrayNotHasKey('challenge', $resp);
    }

    public function test_validate_captcha_token_accepts_correct_answer(): void
    {
        $ip = '10.0.0.6';
        Cache::put("vetops.captcha:{$ip}", ['challenge' => '3 + 4', 'answer' => '7'], now()->addMinutes(10));

        $this->assertTrue($this->auth->validateCaptchaToken($ip, '7'));
        $this->assertTrue($this->auth->validateCaptchaToken($ip, ' 7 '));
        $this->assertFalse($this->auth->validateCaptchaToken($ip, '8'));
        $this->assertFalse($this->auth->validateCaptchaToken($ip, null));
    }

    public function test_attempt_blocks_login_when_over_max_attempts(): void
    {
        $ip = '10.0.0.7';
        $max = (int) config('vetops.max_login_attempts', 10);

        for ($i = 0; $i < $max; $i++) {
            LoginAttempt::create([
                'username'     => 'tryhard',
                'ip_address'   => $ip,
                'success'      => false,
                'attempted_at' => now(),
            ]);
        }

        User::factory()->create([
            'username' => 'tryhard',
            'password' => Hash::make('GoodPass123456!'),
        ]);

        $this->expectException(ValidationException::class);
        $this->auth->attempt('tryhard', 'GoodPass123456!', $ip);
    }

    public function test_attempt_rejects_when_captcha_required_but_missing(): void
    {
        $ip = '10.0.0.8';
        $threshold = (int) config('vetops.captcha_after', 5);

        for ($i = 0; $i < $threshold; $i++) {
            LoginAttempt::create([
                'username'     => 'burn',
                'ip_address'   => $ip,
                'success'      => false,
                'attempted_at' => now(),
            ]);
        }
        User::factory()->create([
            'username' => 'burn',
            'password' => Hash::make('GoodPass123456!'),
        ]);

        try {
            $this->auth->attempt('burn', 'GoodPass123456!', $ip, null);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('captcha_token', $e->errors());
        }
    }

    public function test_refresh_from_cookie_throws_for_invalid_token(): void
    {
        $this->expectException(ValidationException::class);
        $this->auth->refreshFromCookie('not-a-real-token');
    }

    public function test_refresh_from_cookie_returns_user_for_valid_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api-token')->plainTextToken;

        $result = $this->auth->refreshFromCookie($token);

        $this->assertEquals($user->id, $result['user']->id);
        $this->assertArrayHasKey('requires_password_change', $result);
    }

    public function test_change_password_requires_correct_current_password(): void
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create(['password' => Hash::make('CorrectCurrentPass12!')]);
        $this->auth->changePassword($user, 'WrongCurrent', 'ValidNewPass12345!');
    }

    public function test_change_password_updates_hash_and_timestamp(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPass1234567!')]);

        $this->auth->changePassword($user, 'OldPass1234567!', 'BrandNewPass456!');

        $user->refresh();
        $this->assertTrue(Hash::check('BrandNewPass456!', $user->password));
        $this->assertNotNull($user->password_changed_at);
    }

    public function test_validate_password_strength_enforces_minimum_length(): void
    {
        $this->expectException(ValidationException::class);
        $this->auth->validatePasswordStrength('short12');
    }

    public function test_validate_password_strength_accepts_min_length(): void
    {
        // No exception expected — 12 chars is the lower bound.
        $this->auth->validatePasswordStrength('twelveletters');
        $this->addToAssertionCount(1);
    }

    public function test_logout_deletes_current_access_token(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('api-token');

        // Simulate authenticated context pointing at this token instance.
        $user->withAccessToken($token->accessToken);

        $this->auth->logout($user);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }
}
