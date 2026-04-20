<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): User
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    protected function actingAsManager(): User
    {
        $user = User::factory()->manager()->create();
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    protected function actingAsInventoryClerk(): User
    {
        $user = User::factory()->inventoryClerk()->create();
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    protected function actingAsTechnicianDoctor(): User
    {
        $user = User::factory()->create(['role' => 'technician_doctor']);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    protected function actingAsContentEditor(): User
    {
        $user = User::factory()->contentEditor()->create();
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    protected function actingAsContentApprover(): User
    {
        $user = User::factory()->contentApprover()->create();
        $this->actingAs($user, 'sanctum');
        return $user;
    }
}
