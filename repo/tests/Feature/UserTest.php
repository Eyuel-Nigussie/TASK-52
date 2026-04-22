<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users(): void
    {
        $this->actingAsAdmin();
        User::factory()->count(3)->create();

        $response = $this->getJson('/api/users');

        $response->assertStatus(200)
            ->assertJsonPath('total', 4); // 3 + the admin
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $this->actingAsManager();

        $response = $this->getJson('/api/users');

        $response->assertStatus(403);
    }

    public function test_technician_cannot_access_users(): void
    {
        $this->actingAsTechnicianDoctor();

        $response = $this->getJson('/api/users');

        $response->assertStatus(403);
    }

    public function test_admin_can_create_user(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $response = $this->postJson('/api/users', [
            'username'              => 'newclerk',
            'name'                  => 'New Clerk',
            'email'                 => 'newclerk@vetops.local',
            'password'              => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role'                  => 'inventory_clerk',
            'facility_id'           => $facility->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('username', 'newclerk')
            ->assertJsonPath('role', 'inventory_clerk');
        $this->assertDatabaseHas('users', ['username' => 'newclerk']);
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/users', [
            'username'              => 'weakpwd',
            'name'                  => 'Weak User',
            'password'              => 'short',
            'password_confirmation' => 'short',
            'role'                  => 'inventory_clerk',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_duplicate_username_is_rejected(): void
    {
        $this->actingAsAdmin();
        User::factory()->create(['username' => 'takenuser']);

        $response = $this->postJson('/api/users', [
            'username'              => 'takenuser',
            'name'                  => 'Another User',
            'password'              => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role'                  => 'inventory_clerk',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }

    public function test_invalid_role_is_rejected(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/users', [
            'username'              => 'badrole',
            'name'                  => 'Bad Role User',
            'password'              => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role'                  => 'super_hacker',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_admin_can_show_user(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create(['phone_encrypted' => encrypt('(555) 999-0000')]);

        $response = $this->getJson("/api/users/{$user->id}");

        $response->assertStatus(200)
            ->assertJsonPath('phone', '(555) 999-0000');
    }

    public function test_admin_can_update_user_role(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $user = User::factory()->create(['role' => 'inventory_clerk', 'facility_id' => $facility->id]);

        $response = $this->putJson("/api/users/{$user->id}", [
            'role' => 'clinic_manager',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('role', 'clinic_manager');
    }

    public function test_admin_can_deactivate_user(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create(['active' => true]);

        $response = $this->putJson("/api/users/{$user->id}", ['active' => false]);

        $response->assertStatus(200)
            ->assertJsonPath('active', false);
    }

    public function test_admin_can_delete_user(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();

        $response = $this->deleteJson("/api/users/{$user->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->deleteJson("/api/users/{$admin->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete your own account.');
    }

    public function test_filter_by_role(): void
    {
        $this->actingAsAdmin();
        User::factory()->create(['role' => 'inventory_clerk']);
        User::factory()->create(['role' => 'technician_doctor']);

        $response = $this->getJson('/api/users?role=inventory_clerk');

        $response->assertStatus(200);
        foreach ($response->json('data') as $user) {
            $this->assertEquals('inventory_clerk', $user['role']);
        }
    }
}
