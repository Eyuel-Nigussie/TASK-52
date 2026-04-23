<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ContentItem;
use App\Models\Doctor;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\MergeRequest;
use App\Models\Patient;
use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use App\Models\ServiceOrder;
use App\Models\Storeroom;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Covers policies registered in App\Providers\AuthServiceProvider and the
 * named Gates declared there. Uses the $user->can(...) interface so the
 * test passes only if the policy map is wired through the container.
 */
class PolicyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_policies_registered_for_every_mapped_model(): void
    {
        $provider = new \App\Providers\AuthServiceProvider(app());
        $reflection = new \ReflectionClass($provider);
        $policies = $reflection->getProperty('policies');
        $map = $policies->getValue($provider);

        $this->assertNotEmpty($map, 'Policy map must not be empty.');

        foreach ($map as $model => $policy) {
            $this->assertTrue(class_exists($model), "Model missing: {$model}");
            $this->assertTrue(class_exists($policy), "Policy missing: {$policy}");
            $this->assertInstanceOf($policy, Gate::getPolicyFor($model));
        }
    }

    public function test_system_admin_bypasses_every_ability(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertTrue($admin->can('manage-users'));
        $this->assertTrue($admin->can('moderate-reviews'));
        $this->assertTrue($admin->can('approve-content'));
        $this->assertTrue($admin->can('delete', RentalAsset::factory()->create()));
    }

    public function test_rental_asset_policy_enforces_role_and_facility(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();
        $clerk = User::factory()->inventoryClerk()->create(['facility_id' => $facilityA->id]);
        $tech  = User::factory()->create(['role' => 'technician_doctor', 'facility_id' => $facilityA->id]);
        $assetA = RentalAsset::factory()->create(['facility_id' => $facilityA->id]);
        $assetB = RentalAsset::factory()->create(['facility_id' => $facilityB->id]);

        $this->assertTrue($clerk->can('create', RentalAsset::class));
        $this->assertFalse($tech->can('create', RentalAsset::class));
        $this->assertTrue($clerk->can('update', $assetA));
        $this->assertFalse($clerk->can('update', $assetB));
        $this->assertFalse($clerk->can('delete', $assetA));
    }

    public function test_rental_transaction_cancel_requires_manager(): void
    {
        $facility = Facility::factory()->create();
        $manager = User::factory()->manager()->create(['facility_id' => $facility->id]);
        $clerk   = User::factory()->inventoryClerk()->create(['facility_id' => $facility->id]);
        $tx = RentalTransaction::factory()->create(['facility_id' => $facility->id]);

        $this->assertTrue($manager->can('cancel', $tx));
        $this->assertFalse($clerk->can('cancel', $tx));
    }

    public function test_content_editor_cannot_approve_own_draft(): void
    {
        $editor   = User::factory()->contentEditor()->create();
        $approver = User::factory()->contentApprover()->create();
        $draft = ContentItem::factory()->create(['author_id' => $editor->id, 'status' => 'draft']);
        $other = ContentItem::factory()->create(['author_id' => $approver->id, 'status' => 'draft']);

        $this->assertTrue($editor->can('update', $draft));
        $this->assertFalse($editor->can('update', $other), 'Editor must not edit another author\'s draft.');
        $this->assertFalse($editor->can('approve', $draft));
        $this->assertTrue($approver->can('approve', $draft));
        $this->assertTrue($approver->can('publish', $draft));
    }

    public function test_review_moderation_gated_to_manager_and_facility(): void
    {
        $facility = Facility::factory()->create();
        $manager  = User::factory()->manager()->create(['facility_id' => $facility->id]);
        $otherMgr = User::factory()->manager()->create(['facility_id' => Facility::factory()->create()->id]);
        $tech     = User::factory()->create(['role' => 'technician_doctor', 'facility_id' => $facility->id]);
        $review = VisitReview::factory()->create(['facility_id' => $facility->id]);

        $this->assertTrue($manager->can('hide', $review));
        $this->assertTrue($manager->can('respond', $review));
        $this->assertFalse($tech->can('hide', $review));
        $this->assertFalse($otherMgr->can('hide', $review));
    }

    public function test_audit_logs_are_read_only_even_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $log = new AuditLog(['event' => 'test', 'user_id' => $admin->id]);

        $this->assertTrue($admin->can('view', $log));
        $this->assertFalse(Gate::forUser($admin)->getPolicyFor(AuditLog::class)->update($admin, $log));
        $this->assertFalse(Gate::forUser($admin)->getPolicyFor(AuditLog::class)->delete($admin, $log));
    }

    public function test_patient_unmasked_phone_requires_manager(): void
    {
        $facility = Facility::factory()->create();
        $manager  = User::factory()->manager()->create(['facility_id' => $facility->id]);
        $tech     = User::factory()->create(['role' => 'technician_doctor', 'facility_id' => $facility->id]);
        $patient  = Patient::factory()->create(['facility_id' => $facility->id]);

        $this->assertTrue($manager->can('viewUnmaskedPhone', $patient));
        $this->assertFalse($tech->can('viewUnmaskedPhone', $patient));
    }

    public function test_named_gates_map_to_expected_roles(): void
    {
        $admin    = User::factory()->admin()->create();
        $manager  = User::factory()->manager()->create();
        $clerk    = User::factory()->inventoryClerk()->create();
        $tech     = User::factory()->create(['role' => 'technician_doctor']);
        $editor   = User::factory()->contentEditor()->create();
        $approver = User::factory()->contentApprover()->create();

        // manage-users is admin-only (Gate::before still bypasses for admin)
        $this->assertTrue($admin->can('manage-users'));
        $this->assertFalse($manager->can('manage-users'));

        // moderate-reviews: manager + admin
        $this->assertTrue($manager->can('moderate-reviews'));
        $this->assertFalse($clerk->can('moderate-reviews'));

        // approve-content: approver + admin
        $this->assertTrue($approver->can('approve-content'));
        $this->assertFalse($editor->can('approve-content'));

        // issue-inventory: includes technician_doctor
        $this->assertTrue($tech->can('issue-inventory'));
        $this->assertTrue($clerk->can('issue-inventory'));
        $this->assertFalse($editor->can('issue-inventory'));

        // transfer-inventory: excludes technician_doctor
        $this->assertFalse($tech->can('transfer-inventory'));
        $this->assertTrue($clerk->can('transfer-inventory'));
    }

    public function test_user_policy_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create();

        $this->assertTrue($admin->can('update', $target));
        $this->assertFalse($manager->can('update', $target));

        // Defense-in-depth: policy method itself (bypassed by Gate::before)
        // still refuses self-delete, in case the admin bypass is ever removed.
        $policy = new \App\Policies\UserPolicy();
        $this->assertFalse($policy->delete($admin, $admin), 'Admin self-delete rejected at policy layer.');
        $this->assertTrue($policy->delete($admin, $target));
    }

    public function test_inventory_item_create_restricted_to_clerk_and_admin(): void
    {
        $clerk = User::factory()->inventoryClerk()->create();
        $manager = User::factory()->manager()->create();

        $this->assertTrue($clerk->can('create', InventoryItem::class));
        $this->assertFalse($manager->can('create', InventoryItem::class));
    }

    public function test_merge_request_requires_manager(): void
    {
        $facility = \App\Models\Facility::factory()->create();
        $manager  = User::factory()->manager()->create(['facility_id' => $facility->id]);
        $clerk    = User::factory()->inventoryClerk()->create(['facility_id' => $facility->id]);
        $mr       = MergeRequest::factory()->create(['facility_id' => $facility->id]);

        $this->assertTrue($manager->can('approve', $mr));
        $this->assertFalse($clerk->can('view', $mr));
    }

    public function test_storeroom_delete_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $storeroom = Storeroom::factory()->create();

        $this->assertTrue($admin->can('delete', $storeroom));
        $this->assertFalse($manager->can('delete', $storeroom));
    }

    public function test_non_clinical_roles_cannot_create_patients(): void
    {
        $clerk   = User::factory()->inventoryClerk()->create();
        $editor  = User::factory()->contentEditor()->create();
        $approver = User::factory()->contentApprover()->create();

        foreach ([$clerk, $editor, $approver] as $user) {
            $this->assertFalse($user->can('create', Patient::class),
                "Role {$user->role} must not create patients.");
        }
    }

    public function test_clinical_roles_can_create_patients(): void
    {
        $facility = Facility::factory()->create();
        $tech    = User::factory()->create(['role' => 'technician_doctor', 'facility_id' => $facility->id]);
        $manager = User::factory()->manager()->create(['facility_id' => $facility->id]);
        $admin   = User::factory()->admin()->create();

        $this->assertTrue($tech->can('create', Patient::class));
        $this->assertTrue($manager->can('create', Patient::class));
        $this->assertTrue($admin->can('create', Patient::class));
    }

    public function test_non_clinical_roles_cannot_update_patients(): void
    {
        $facility = Facility::factory()->create();
        $patient  = Patient::factory()->create(['facility_id' => $facility->id]);
        $clerk    = User::factory()->inventoryClerk()->create(['facility_id' => $facility->id]);
        $editor   = User::factory()->contentEditor()->create(['facility_id' => $facility->id]);

        $this->assertFalse($clerk->can('update', $patient));
        $this->assertFalse($editor->can('update', $patient));
    }

    public function test_non_clinical_roles_cannot_create_visits(): void
    {
        $clerk  = User::factory()->inventoryClerk()->create();
        $editor = User::factory()->contentEditor()->create();

        $this->assertFalse($clerk->can('create', Visit::class));
        $this->assertFalse($editor->can('create', Visit::class));
    }

    public function test_clinical_roles_can_create_and_update_visits(): void
    {
        $facility = Facility::factory()->create();
        $doctor   = Doctor::factory()->create(['facility_id' => $facility->id]);
        $patient  = Patient::factory()->create(['facility_id' => $facility->id]);
        $visit    = Visit::factory()->create(['facility_id' => $facility->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id]);
        $tech     = User::factory()->create(['role' => 'technician_doctor', 'facility_id' => $facility->id]);

        $this->assertTrue($tech->can('create', Visit::class));
        $this->assertTrue($tech->can('update', $visit));
    }

    public function test_non_clinical_roles_cannot_create_service_orders(): void
    {
        $clerk  = User::factory()->inventoryClerk()->create();
        $editor = User::factory()->contentEditor()->create();

        $this->assertFalse($clerk->can('create', ServiceOrder::class));
        $this->assertFalse($editor->can('create', ServiceOrder::class));
    }

    public function test_non_clinical_roles_denied_at_route_level_for_clinical_endpoints(): void
    {
        $this->actingAsInventoryClerk();

        // Non-clinical role must get 403 from route-role middleware on clinical create/write routes.
        $this->postJson('/api/patients', ['name' => 'Test'])->assertStatus(403);
        $this->postJson('/api/visits', ['visit_date' => now()->toDateString()])->assertStatus(403);
        $this->postJson('/api/service-orders', [])->assertStatus(403);
    }
}
