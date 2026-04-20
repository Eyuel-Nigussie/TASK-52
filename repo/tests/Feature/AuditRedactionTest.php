<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the redaction contract: sensitive keys must never reach the audit
 * log verbatim. Replace with ***REDACTED*** instead. See AuditService.
 */
class AuditRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_redacted(): void
    {
        $service = app(AuditService::class);
        $this->actingAsAdmin();

        $service->log('test.sensitive', 'Test', 1, null, [
            'username' => 'alice',
            'password' => 'SuperSecret123!',
        ]);

        $log = AuditLog::latest('id')->first();
        $this->assertSame('alice', $log->new_values['username']);
        $this->assertSame('***REDACTED***', $log->new_values['password']);
    }

    public function test_encrypted_phone_keys_are_redacted(): void
    {
        $service = app(AuditService::class);
        $this->actingAsAdmin();

        $service->log('test.phone', null, null, null, [
            'phone_encrypted'       => 'gibberish-cipher-text',
            'owner_phone_encrypted' => 'more-cipher',
        ]);

        $log = AuditLog::latest('id')->first();
        $this->assertSame('***REDACTED***', $log->new_values['phone_encrypted']);
        $this->assertSame('***REDACTED***', $log->new_values['owner_phone_encrypted']);
    }

    public function test_nested_password_fields_are_redacted(): void
    {
        $service = app(AuditService::class);
        $this->actingAsAdmin();

        $service->log('test.nested', null, null, null, [
            'payload' => [
                'user' => [
                    'name'             => 'bob',
                    'password'         => 'nope',
                    'remember_token'   => 'abc123',
                ],
            ],
        ]);

        $log = AuditLog::latest('id')->first();
        $this->assertSame('bob', $log->new_values['payload']['user']['name']);
        $this->assertSame('***REDACTED***', $log->new_values['payload']['user']['password']);
        $this->assertSame('***REDACTED***', $log->new_values['payload']['user']['remember_token']);
    }

    public function test_captcha_and_token_fields_are_redacted(): void
    {
        $service = app(AuditService::class);
        $this->actingAsAdmin();

        $service->log('test.tokens', null, null, null, [
            'captcha_token' => '42',
            'api_token'     => 'bearer-abc',
            'token'         => 'xyz',
        ]);

        $log = AuditLog::latest('id')->first();
        $this->assertSame('***REDACTED***', $log->new_values['captcha_token']);
        $this->assertSame('***REDACTED***', $log->new_values['api_token']);
        $this->assertSame('***REDACTED***', $log->new_values['token']);
    }

    public function test_non_sensitive_fields_are_preserved_verbatim(): void
    {
        $service = app(AuditService::class);
        $this->actingAsAdmin();

        $service->log('test.clean', null, null, null, [
            'username' => 'alice',
            'role'     => 'inventory_clerk',
            'active'   => true,
        ]);

        $log = AuditLog::latest('id')->first();
        $this->assertSame('alice', $log->new_values['username']);
        $this->assertSame('inventory_clerk', $log->new_values['role']);
        $this->assertTrue($log->new_values['active']);
    }
}
