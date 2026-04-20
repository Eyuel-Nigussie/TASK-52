<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Idempotent seeder that provisions the initial system administrator only.
 *
 * Intended for greenfield production deploys where running the full
 * DatabaseSeeder (which also creates demo facilities, users, and stock) is
 * undesirable. The runbook instructs operators to call this seeder explicitly
 * after the first migration so that only the privileged admin account exists.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name'                => 'System Administrator',
                'email'               => 'admin@vetops.local',
                'password'            => Hash::make('VetOps!Tmp-Admin1'),
                'role'                => 'system_admin',
                'facility_id'         => null,
                'active'              => true,
                'password_changed_at' => null,
            ],
        );
    }
}
