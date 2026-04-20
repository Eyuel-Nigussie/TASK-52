<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DataVersion;
use App\Models\Facility;
use App\Models\MergeRequest;
use App\Models\Service;
use App\Models\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Service entity-type merge: pricings from the source service must be
 * relinked to the target, and the source must be soft-deleted.
 */
class ServiceMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_approving_service_merge_relinks_pricings_and_soft_deletes_source(): void
    {
        $admin = $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $source = Service::factory()->create(['name' => 'Consultation Dupe', 'category' => 'clinical']);
        $target = Service::factory()->create(['name' => 'Consultation Canonical', 'category' => 'clinical']);

        $pricing1 = ServicePricing::factory()->create(['service_id' => $source->id, 'facility_id' => $facility->id]);
        $pricing2 = ServicePricing::factory()->create(['service_id' => $source->id, 'facility_id' => $facility->id]);

        $merge = MergeRequest::create([
            'entity_type'  => 'service',
            'source_id'    => $source->id,
            'target_id'    => $target->id,
            'status'       => 'pending',
            'requested_by' => $admin->id,
            'resolution_rules' => ['keep' => 'target_name'],
        ]);

        $this->postJson("/api/merge-requests/{$merge->id}/approve")->assertStatus(200);

        // Both pricings now point at target.
        $this->assertEquals($target->id, ServicePricing::find($pricing1->id)->service_id);
        $this->assertEquals($target->id, ServicePricing::find($pricing2->id)->service_id);

        // Source is soft-deleted; target is alive.
        $this->assertSoftDeleted('services', ['id' => $source->id]);
        $this->assertDatabaseHas('services', ['id' => $target->id, 'deleted_at' => null]);
    }

    public function test_service_merge_records_pre_merge_snapshots(): void
    {
        $admin = $this->actingAsAdmin();
        $source = Service::factory()->create();
        $target = Service::factory()->create();

        $merge = MergeRequest::create([
            'entity_type'  => 'service',
            'source_id'    => $source->id,
            'target_id'    => $target->id,
            'status'       => 'pending',
            'requested_by' => $admin->id,
        ]);

        $this->postJson("/api/merge-requests/{$merge->id}/approve")->assertStatus(200);

        $this->assertGreaterThan(0, DataVersion::where('entity_type', Service::class)->where('entity_id', $source->id)->count());
        $this->assertGreaterThan(0, DataVersion::where('entity_type', Service::class)->where('entity_id', $target->id)->count());
    }

    public function test_store_accepts_service_entity_type(): void
    {
        $this->actingAsAdmin();
        $source = Service::factory()->create();
        $target = Service::factory()->create();

        $response = $this->postJson('/api/merge-requests', [
            'entity_type' => 'service',
            'source_id'   => $source->id,
            'target_id'   => $target->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('entity_type', 'service');
    }
}
