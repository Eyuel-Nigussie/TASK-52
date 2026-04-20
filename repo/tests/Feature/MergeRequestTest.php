<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MergeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MergeRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_merge_requests(): void
    {
        $this->actingAsAdmin();
        MergeRequest::factory()->count(3)->create();

        $response = $this->getJson('/api/merge-requests');

        $response->assertStatus(200)
            ->assertJsonPath('total', 3);
    }

    public function test_manager_can_list_merge_requests(): void
    {
        $this->actingAsManager();
        MergeRequest::factory()->count(2)->create();

        $response = $this->getJson('/api/merge-requests');

        $response->assertStatus(200)
            ->assertJsonPath('total', 2);
    }

    public function test_clerk_cannot_access_merge_requests(): void
    {
        $this->actingAsInventoryClerk();

        $response = $this->getJson('/api/merge-requests');

        $response->assertStatus(403);
    }

    public function test_technician_cannot_access_merge_requests(): void
    {
        $this->actingAsTechnicianDoctor();

        $response = $this->getJson('/api/merge-requests');

        $response->assertStatus(403);
    }

    public function test_admin_can_create_merge_request(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/merge-requests', [
            'entity_type' => 'patient',
            'source_id'   => 1,
            'target_id'   => 2,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('entity_type', 'patient');
    }

    public function test_source_and_target_must_differ(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/merge-requests', [
            'entity_type' => 'patient',
            'source_id'   => 5,
            'target_id'   => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['target_id']);
    }

    public function test_admin_can_approve_pending_merge_request(): void
    {
        $admin = $this->actingAsAdmin();
        $merge = MergeRequest::factory()->create([
            'status'       => 'pending',
            'requested_by' => $admin->id,
        ]);

        $response = $this->postJson("/api/merge-requests/{$merge->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('merge_requests', [
            'id'     => $merge->id,
            'status' => 'approved',
        ]);
    }

    public function test_cannot_approve_non_pending_merge_request(): void
    {
        $admin = $this->actingAsAdmin();
        $merge = MergeRequest::factory()->create([
            'status'       => 'approved',
            'requested_by' => $admin->id,
        ]);

        $response = $this->postJson("/api/merge-requests/{$merge->id}/approve");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Merge request is not pending.');
    }

    public function test_admin_can_reject_merge_request(): void
    {
        $admin = $this->actingAsAdmin();
        $merge = MergeRequest::factory()->create([
            'status'       => 'pending',
            'requested_by' => $admin->id,
        ]);

        $response = $this->postJson("/api/merge-requests/{$merge->id}/reject");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'rejected');
    }

    public function test_can_filter_by_entity_type(): void
    {
        $admin = $this->actingAsAdmin();
        MergeRequest::factory()->create(['entity_type' => 'patient', 'requested_by' => $admin->id]);
        MergeRequest::factory()->create(['entity_type' => 'doctor', 'requested_by' => $admin->id]);
        MergeRequest::factory()->create(['entity_type' => 'patient', 'requested_by' => $admin->id]);

        $response = $this->getJson('/api/merge-requests?entity_type=patient');

        $response->assertStatus(200)
            ->assertJsonPath('total', 2);
    }

    public function test_can_filter_by_status(): void
    {
        $admin = $this->actingAsAdmin();
        MergeRequest::factory()->create(['status' => 'pending', 'requested_by' => $admin->id]);
        MergeRequest::factory()->create(['status' => 'approved', 'requested_by' => $admin->id]);
        MergeRequest::factory()->create(['status' => 'pending', 'requested_by' => $admin->id]);

        $response = $this->getJson('/api/merge-requests?status=pending');

        $response->assertStatus(200)
            ->assertJsonPath('total', 2);
    }
}
