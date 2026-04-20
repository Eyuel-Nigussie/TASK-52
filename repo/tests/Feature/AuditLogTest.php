<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Facility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_created_on_facility_create(): void
    {
        $this->actingAsAdmin();
        $facility = Facility::factory()->create();

        $this->postJson('/api/facilities', [
            'external_key' => 'FAC-AUDIT-001',
            'name'         => 'Audit Test Hospital',
            'address'      => '123 Main',
            'city'         => 'Chicago',
            'state'        => 'IL',
            'zip'          => '60601',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'facility.create',
            'entity_type' => Facility::class,
        ]);
    }

    public function test_audit_log_immutable(): void
    {
        $log = AuditLog::create([
            'user_id'    => 1,
            'action'     => 'test.action',
            'created_at' => now(),
        ]);

        $originalAction = $log->action;
        $log->action = 'modified.action';
        $log->save();

        $this->assertEquals($originalAction, $log->fresh()->action);
    }

    public function test_audit_log_not_deletable(): void
    {
        $log = AuditLog::create([
            'user_id'    => 1,
            'action'     => 'test.action',
            'created_at' => now(),
        ]);

        $log->delete();

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_can_list_audit_logs_as_admin(): void
    {
        $this->actingAsAdmin();
        AuditLog::create(['action' => 'test.event', 'created_at' => now()]);

        $response = $this->getJson('/api/audit-logs');
        $response->assertStatus(200)->assertJsonStructure(['data']);
    }

    public function test_non_admin_cannot_access_audit_logs(): void
    {
        $this->actingAsTechnicianDoctor();

        $response = $this->getJson('/api/audit-logs');
        $response->assertStatus(403);
    }

    public function test_login_events_are_audited(): void
    {
        \App\Models\User::factory()->create([
            'username' => 'audituser',
            'password' => \Illuminate\Support\Facades\Hash::make('Password123!'),
        ]);

        $this->postJson('/api/auth/login', [
            'username' => 'audituser',
            'password' => 'Password123!',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);
    }

    public function test_failed_login_events_are_audited(): void
    {
        \App\Models\User::factory()->create(['username' => 'faileduser']);

        $this->postJson('/api/auth/login', [
            'username' => 'faileduser',
            'password' => 'wrongpassword',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function test_audit_purge_command(): void
    {
        // Create log older than 7 years
        AuditLog::create([
            'action'     => 'old.event',
            'created_at' => now()->subYears(8),
        ]);

        // Create recent log
        AuditLog::create([
            'action'     => 'recent.event',
            'created_at' => now(),
        ]);

        $this->artisan('vetops:purge-audit-logs')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'old.event']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'recent.event']);
    }

    public function test_filter_audit_logs_by_action(): void
    {
        $this->actingAsAdmin();
        AuditLog::create(['action' => 'facility.create', 'created_at' => now()]);
        AuditLog::create(['action' => 'facility.update', 'created_at' => now()]);
        AuditLog::create(['action' => 'user.delete', 'created_at' => now()]);

        $response = $this->getJson('/api/audit-logs?action=facility');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('total'));
    }

    public function test_filter_audit_logs_by_entity_type(): void
    {
        $this->actingAsAdmin();
        AuditLog::create(['action' => 'evt', 'entity_type' => Facility::class, 'created_at' => now()]);
        AuditLog::create(['action' => 'evt', 'entity_type' => Facility::class, 'created_at' => now()]);
        AuditLog::create(['action' => 'evt', 'entity_type' => \App\Models\User::class, 'created_at' => now()]);

        $response = $this->getJson('/api/audit-logs?entity_type=' . urlencode(Facility::class));

        $response->assertStatus(200)
            ->assertJsonPath('total', 2);
    }

    public function test_filter_audit_logs_by_date_range(): void
    {
        $this->actingAsAdmin();
        AuditLog::create(['action' => 'old', 'created_at' => now()->subDays(30)]);
        AuditLog::create(['action' => 'recent', 'created_at' => now()]);

        $from = now()->subDays(7)->toDateString();
        $response = $this->getJson("/api/audit-logs?from={$from}");

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_audit_log_export_returns_csv(): void
    {
        $this->actingAsAdmin();
        AuditLog::create(['action' => 'exported.event', 'created_at' => now()]);

        $response = $this->getJson('/api/audit-logs/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('exported.event', $response->streamedContent());
        $this->assertStringContainsString('id,user,action', $response->streamedContent());
    }

    public function test_manager_can_list_audit_logs(): void
    {
        $this->actingAsManager();
        AuditLog::create(['action' => 'viewed', 'created_at' => now()]);

        $response = $this->getJson('/api/audit-logs');

        $response->assertStatus(200);
    }

    public function test_clerk_cannot_list_audit_logs(): void
    {
        $this->actingAsInventoryClerk();

        $response = $this->getJson('/api/audit-logs');

        $response->assertStatus(403);
    }
}
