<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Facility;
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
        $facility = Facility::factory()->create();
        $user = User::factory()->manager()->create(['facility_id' => $facility->id]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    protected function actingAsInventoryClerk(): User
    {
        $facility = Facility::factory()->create();
        $user = User::factory()->inventoryClerk()->create(['facility_id' => $facility->id]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    protected function actingAsTechnicianDoctor(): User
    {
        $facility = Facility::factory()->create();
        $user = User::factory()->create(['role' => 'technician_doctor', 'facility_id' => $facility->id]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    protected function actingAsContentEditor(): User
    {
        $facility = Facility::factory()->create();
        $user = User::factory()->contentEditor()->create(['facility_id' => $facility->id]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    protected function actingAsContentApprover(): User
    {
        $facility = Facility::factory()->create();
        $user = User::factory()->contentApprover()->create(['facility_id' => $facility->id]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    /**
     * Prepare a JSON request that includes an encrypted vetops_session cookie.
     * disableCookieEncryption() prevents the EncryptCookies middleware from
     * attempting a second decryption pass on the already-encrypted value.
     */
    protected function withRefreshCookie(string $token): static
    {
        return $this->withCredentials()
            ->disableCookieEncryption()
            ->withCookie('vetops_session', encrypt($token));
    }
}
