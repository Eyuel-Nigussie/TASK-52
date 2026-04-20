<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class InactivityTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_token_allows_api_access(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertStatus(200);
    }

    public function test_expired_token_returns_401_and_is_deleted(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 15]);
        $plainText = $user->createToken('test')->plainTextToken;
        [$id] = explode('|', $plainText, 2);

        // Simulate previous activity 30 minutes ago (past 15-min timeout).
        Cache::put("vetops.token_idle:{$id}", now()->subMinutes(30)->toIso8601String(), now()->addMinutes(120));

        $response = $this->withToken($plainText)->getJson('/api/auth/me');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'SESSION_EXPIRED');
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
    }

    public function test_token_within_timeout_stays_alive(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 15]);
        $plainText = $user->createToken('test')->plainTextToken;
        [$id] = explode('|', $plainText, 2);

        Cache::put("vetops.token_idle:{$id}", now()->subMinutes(5)->toIso8601String(), now()->addMinutes(120));

        $response = $this->withToken($plainText)->getJson('/api/auth/me');

        $response->assertStatus(200);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $id]);
    }

    public function test_user_specific_timeout_overrides_default(): void
    {
        // User has a custom 5-minute timeout (shorter than the global 15).
        $user = User::factory()->create(['inactivity_timeout' => 5]);
        $plainText = $user->createToken('test')->plainTextToken;
        [$id] = explode('|', $plainText, 2);

        // 7 minutes idle — beyond the user's 5-minute timeout even though
        // it would fit the global 15-minute default.
        Cache::put("vetops.token_idle:{$id}", now()->subMinutes(7)->toIso8601String(), now()->addMinutes(120));

        $response = $this->withToken($plainText)->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_subsequent_requests_update_idle_checkpoint(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 15]);
        $plainText = $user->createToken('test')->plainTextToken;
        [$id] = explode('|', $plainText, 2);

        $this->withToken($plainText)->getJson('/api/auth/me')->assertStatus(200);

        // The middleware should have written the checkpoint.
        $this->assertNotNull(Cache::get("vetops.token_idle:{$id}"));
    }
}
