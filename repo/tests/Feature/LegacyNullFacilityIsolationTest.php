<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Facility;
use App\Models\MergeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyNullFacilityIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_facility_manager_cannot_view_department_in_policy_path(): void
    {
        $manager = User::factory()->manager()->create(['facility_id' => null]);
        $dept = Department::factory()->create();

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/departments/{$dept->id}", ['name' => 'Nope'])
            ->assertStatus(403);
    }

    public function test_null_facility_manager_cannot_approve_facility_scoped_merge_request(): void
    {
        $manager = User::factory()->manager()->create(['facility_id' => null]);
        $facility = Facility::factory()->create();
        $merge = MergeRequest::factory()->create([
            'facility_id' => $facility->id,
            'status' => 'pending',
            'entity_type' => 'patient',
        ]);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/merge-requests/{$merge->id}/approve")
            ->assertStatus(403);
    }

    public function test_admin_still_can_view_and_approve_when_facility_mismatch_exists(): void
    {
        $admin = User::factory()->admin()->create(['facility_id' => null]);
        $facility = Facility::factory()->create();
        $merge = MergeRequest::factory()->create([
            'facility_id' => $facility->id,
            'status' => 'pending',
            'entity_type' => 'patient',
            'source_id' => 100,
            'target_id' => 101,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/merge-requests')
            ->assertStatus(200);

        // Execution may fail because fixture ids are synthetic, but policy gate
        // must allow admin through (i.e. not 403).
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/merge-requests/{$merge->id}/approve");

        $this->assertNotEquals(403, $response->status());
    }

    public function test_content_show_success_path_is_exercised_for_authoring_user(): void
    {
        $approver = User::factory()->contentApprover()->create();
        $item = \App\Models\ContentItem::factory()->create(['status' => 'draft']);

        $this->actingAs($approver, 'sanctum')
            ->getJson("/api/content/{$item->id}")
            ->assertStatus(200)
            ->assertJsonPath('id', $item->id);
    }
}
