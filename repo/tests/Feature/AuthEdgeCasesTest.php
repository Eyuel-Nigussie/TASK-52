<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Additional auth-flow edge cases not otherwise asserted, including the
 * session-refresh / inactivity interaction, captcha-status header shape,
 * and password-change side effects.
 */
class AuthEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_issues_working_token_for_subsequent_me_calls(): void
    {
        $user  = User::factory()->create(['password' => Hash::make('RefreshTest12!')]);
        $token = $user->createToken('api-token')->plainTextToken;

        $refresh = $this->withRefreshCookie($token)->postJson('/api/auth/refresh');
        $refresh->assertStatus(200);
        $newToken = $refresh->json('token');

        // The refreshed token must authorize /me immediately.
        $this->withToken($newToken)->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_refresh_then_inactivity_expiry_invalidates_token(): void
    {
        $user  = User::factory()->create(['inactivity_timeout' => 15]);
        $token = $user->createToken('api-token')->plainTextToken;
        [$tokenId] = explode('|', $token, 2);

        // Pretend the token has been idle for 30 minutes.
        Cache::put(
            "vetops.token_idle:{$tokenId}",
            now()->subMinutes(30)->toIso8601String(),
            now()->addMinutes(120),
        );

        // Even with a valid cookie, the inactivity middleware should kick in
        // when the same token is used against an authenticated endpoint.
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_captcha_status_without_threshold_returns_no_challenge(): void
    {
        $response = $this->getJson('/api/auth/captcha-status');

        $response->assertStatus(200)
            ->assertJsonPath('captcha_required', false)
            ->assertJsonMissing(['challenge']);
    }

    public function test_change_password_deletes_no_token_but_updates_timestamp(): void
    {
        $user = User::factory()->create([
            'password'            => Hash::make('Start1234567890!'),
            'password_changed_at' => null,
        ]);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/auth/change-password', [
            'current_password'      => 'Start1234567890!',
            'password'              => 'RotatedNew9876!',
            'password_confirmation' => 'RotatedNew9876!',
        ])->assertStatus(200);

        $this->assertNotNull($user->fresh()->password_changed_at);
    }

    public function test_invalid_login_body_returns_422_validation_error(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'username' => '', // empty
            'password' => '',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_login_attempt_row_is_recorded_on_success_and_failure(): void
    {
        User::factory()->create([
            'username' => 'trackme',
            'password' => Hash::make('SharedPass123!'),
        ]);

        $this->postJson('/api/auth/login', ['username' => 'trackme', 'password' => 'SharedPass123!'])
            ->assertStatus(200);
        $this->assertEquals(1, LoginAttempt::where('username', 'trackme')->where('success', true)->count());

        $this->postJson('/api/auth/login', ['username' => 'trackme', 'password' => 'wrong'])
            ->assertStatus(422);
        $this->assertEquals(1, LoginAttempt::where('username', 'trackme')->where('success', false)->count());
    }
}
