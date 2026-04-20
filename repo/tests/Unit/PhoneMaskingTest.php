<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Patient;
use App\Models\User;
use Tests\TestCase;

class PhoneMaskingTest extends TestCase
{
    public function test_patient_phone_masked_correctly(): void
    {
        $patient = new Patient();
        $patient->owner_phone_encrypted = encrypt('(555) 123-4567');

        $masked = $patient->getMaskedOwnerPhone();

        $this->assertMatchesRegularExpression('/\(\d{3}\) \*{3}-\d{4}/', $masked);
        $this->assertStringContainsString('555', $masked);
        $this->assertStringContainsString('4567', $masked);
        $this->assertStringNotContainsString('123', $masked);
    }

    public function test_patient_phone_reveals_area_code_and_last_4(): void
    {
        $patient = new Patient();
        $patient->owner_phone_encrypted = encrypt('(555) 987-6543');

        $masked = $patient->getMaskedOwnerPhone();

        $this->assertEquals('(555) ***-6543', $masked);
    }

    public function test_null_phone_returns_null(): void
    {
        $patient = new Patient();
        $patient->owner_phone_encrypted = null;

        $this->assertNull($patient->getMaskedOwnerPhone());
    }

    public function test_user_phone_masked_correctly(): void
    {
        $user = new User();
        $user->phone_encrypted = encrypt('(555) 111-2222');

        $masked = $user->getMaskedPhone();

        $this->assertEquals('(555) ***-2222', $masked);
    }

    public function test_masked_phone_format(): void
    {
        $patient = new Patient();
        $patient->owner_phone_encrypted = encrypt('(800) 555-1234');

        $masked = $patient->getMaskedOwnerPhone();

        // Format: (NXX) ***-XXXX
        $this->assertMatchesRegularExpression('/^\(\d{3}\) \*{3}-\d{4}$/', $masked);
    }
}
