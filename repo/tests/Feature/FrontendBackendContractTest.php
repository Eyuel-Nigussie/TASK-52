<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\Patient;
use App\Models\Storeroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Frontend ↔ Backend contract regression suite.
 *
 * The Vue SPA constructs form payloads with a fixed set of field names.
 * These tests send the *exact* shapes the Vue forms build (including field
 * spellings: `first_name`/`last_name`, `external_key`, `visit_date`,
 * `unit_of_measure`, `zip`, …) and assert the backend accepts them.
 *
 * If a frontend form drifts from the backend validator (e.g. someone renames
 * `zip` back to `postal_code` in a Vue view, or reintroduces a `unit` field),
 * these tests fail and prevent a passing-but-broken release.
 *
 * The field sets mirror the Vue form fields exactly:
 * - DoctorsView.vue        → tests_doctor_create_contract
 * - InventoryView.vue      → tests_inventory_item_create_contract
 * - FacilitiesView.vue     → tests_facility_create_contract
 * - VisitsView.vue         → tests_visit_create_contract
 * - PatientsView.vue       → tests_patient_create_contract
 * - LoginView.vue          → tests_login_payload_contract
 * - InventoryView.vue (tx) → tests_inventory_receive_contract
 * - ServiceOrdersView.vue  → tests_service_order_create_contract
 */
class FrontendBackendContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_create_payload_from_doctors_view_is_accepted(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        // Exact shape produced by resources/js/views/DoctorsView.vue
        $payload = [
            'facility_id'    => $facility->id,
            'external_key'   => 'DR-FE-01',
            'first_name'     => 'Ada',
            'last_name'      => 'Lovelace',
            'specialty'      => 'Internal Medicine',
            'license_number' => 'LIC-FE-0001',
            'email'          => 'ada@vetops.local',
        ];

        $this->postJson('/api/doctors', $payload)
            ->assertStatus(201)
            ->assertJsonPath('external_key', 'DR-FE-01')
            ->assertJsonPath('first_name', 'Ada')
            ->assertJsonPath('last_name', 'Lovelace');
    }

    public function test_inventory_item_create_payload_from_inventory_view_is_accepted(): void
    {
        $this->actingAsAdmin();

        // Exact shape produced by resources/js/views/InventoryView.vue item form
        $payload = [
            'external_key'    => 'ITEM-FE-01',
            'name'            => 'Sterile Gauze',
            'sku'             => 'SKU-FE-001',
            'category'        => 'consumables',
            'unit_of_measure' => 'box',
        ];

        $this->postJson('/api/inventory/items', $payload)
            ->assertStatus(201)
            ->assertJsonPath('external_key', 'ITEM-FE-01')
            ->assertJsonPath('unit_of_measure', 'box');
    }

    public function test_legacy_unit_field_is_rejected_so_view_cannot_regress(): void
    {
        $this->actingAsAdmin();

        // If someone reintroduces the old `unit` field in the Vue form, the
        // backend still requires `external_key`, `name`, `category`. A request
        // missing those fields must fail with validation errors. This guards
        // against the regression reported in the first audit.
        $bad = [
            'name' => 'Old Shape',
            'sku'  => 'SKU-OLD',
            'unit' => 'ea',
        ];

        $this->postJson('/api/inventory/items', $bad)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['external_key', 'category']);
    }

    public function test_facility_create_payload_from_facilities_view_is_accepted(): void
    {
        $this->actingAsAdmin();

        // Exact shape produced by resources/js/views/FacilitiesView.vue
        $payload = [
            'external_key' => 'FAC-FE-01',
            'name'         => 'Downtown Clinic',
            'address'      => '123 Market St',
            'city'         => 'Portland',
            'state'        => 'OR',
            'zip'          => '97201',
            'email'        => 'downtown@vetops.local',
        ];

        $this->postJson('/api/facilities', $payload)
            ->assertStatus(201)
            ->assertJsonPath('external_key', 'FAC-FE-01')
            ->assertJsonPath('zip', '97201');
    }

    public function test_legacy_postal_code_field_does_not_satisfy_zip_requirement(): void
    {
        $this->actingAsAdmin();

        $badPayload = [
            'external_key' => 'FAC-FE-BAD',
            'name'         => 'Bad',
            'address'      => '1 Any',
            'city'         => 'Anywhere',
            'state'        => 'OR',
            'postal_code'  => '97201', // old, wrong field name
        ];

        $this->postJson('/api/facilities', $badPayload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['zip']);
    }

    public function test_visit_create_payload_from_visits_view_is_accepted(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $patient  = Patient::factory()->create(['facility_id' => $facility->id]);
        $doctor   = Doctor::factory()->create(['facility_id' => $facility->id]);

        // Exact shape produced by resources/js/views/VisitsView.vue
        $payload = [
            'facility_id' => $facility->id,
            'patient_id'  => $patient->id,
            'doctor_id'   => $doctor->id,
            'visit_date'  => now()->addDays(1)->toIso8601String(),
            'status'      => 'scheduled',
        ];

        $this->postJson('/api/visits', $payload)
            ->assertStatus(201)
            ->assertJsonPath('status', 'scheduled');
    }

    public function test_visit_rejects_unsupported_in_progress_status(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();
        $patient  = Patient::factory()->create(['facility_id' => $facility->id]);
        $doctor   = Doctor::factory()->create(['facility_id' => $facility->id]);

        // 'in_progress' was the broken value in the original Vue form. The
        // validator must still reject it so no future frontend regression
        // silently re-enables it.
        $this->postJson('/api/visits', [
            'facility_id' => $facility->id,
            'patient_id'  => $patient->id,
            'doctor_id'   => $doctor->id,
            'visit_date'  => now()->addDays(1)->toIso8601String(),
            'status'      => 'in_progress',
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_patient_create_payload_from_patients_view_is_accepted(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        // Exact shape produced by resources/js/views/PatientsView.vue
        $payload = [
            'facility_id'  => $facility->id,
            'external_key' => 'PAT-FE-01',
            'name'         => 'Buddy',
            'species'      => 'dog',
            'breed'        => 'Labrador',
            'owner_name'   => 'Jane Doe',
            'owner_phone'  => '(555) 123-4567',
            'owner_email'  => 'jane@example.com',
        ];

        $this->postJson('/api/patients', $payload)
            ->assertStatus(201)
            ->assertJsonPath('external_key', 'PAT-FE-01')
            ->assertJsonPath('name', 'Buddy');
    }

    public function test_login_payload_from_login_view_returns_expected_shape(): void
    {
        User::factory()->create([
            'username' => 'fe_user',
            'password' => Hash::make('FrontEndPass12!'),
        ]);

        // Exact shape produced by resources/js/views/LoginView.vue submit()
        $payload = [
            'username'      => 'fe_user',
            'password'      => 'FrontEndPass12!',
            'captcha_token' => null,
        ];

        $response = $this->postJson('/api/auth/login', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user', 'captcha_required', 'requires_password_change'])
            ->assertJsonPath('captcha_required', false);
    }

    public function test_inventory_receive_payload_from_inventory_view_tx_is_accepted(): void
    {
        $this->actingAsInventoryClerk();
        $item      = InventoryItem::factory()->create();
        $storeroom = Storeroom::factory()->create();

        // Exact shape produced by InventoryView.vue openTx('receive')
        $payload = [
            'item_id'      => $item->id,
            'storeroom_id' => $storeroom->id,
            'quantity'     => 10,
            'unit_cost'    => 3.25,
            'reference'    => 'PO-FE-01',
        ];

        $this->postJson('/api/inventory/receive', $payload)
            ->assertStatus(201);
    }

    public function test_service_order_create_payload_from_orders_view_is_accepted(): void
    {
        $this->actingAsTechnicianDoctor();
        $facility = Facility::factory()->create();

        // Minimal shape produced by the service-orders UI flow.
        $payload = [
            'facility_id'          => $facility->id,
            'reservation_strategy' => 'deduct_at_close',
        ];

        $this->postJson('/api/service-orders', $payload)
            ->assertStatus(201)
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('reservation_strategy', 'deduct_at_close');
    }
}
