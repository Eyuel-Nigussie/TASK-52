<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Doctor;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\Patient;
use App\Models\RentalAsset;
use App\Models\Storeroom;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $facility1 = Facility::factory()->create([
            'external_key' => 'FAC-001',
            'name'         => 'Downtown Veterinary Hospital',
        ]);

        $facility2 = Facility::factory()->create([
            'external_key' => 'FAC-002',
            'name'         => 'Westside Animal Clinic',
        ]);

        $dept1 = Department::factory()->create(['facility_id' => $facility1->id, 'name' => 'Surgery', 'external_key' => 'DEPT-001']);
        $dept2 = Department::factory()->create(['facility_id' => $facility1->id, 'name' => 'Emergency', 'external_key' => 'DEPT-002']);

        // Seed accounts — password_changed_at is null so users are forced to change on first login.
        User::create([
            'username'            => 'admin',
            'name'                => 'System Administrator',
            'email'               => 'admin@vetops.local',
            'password'            => Hash::make('VetOps!Tmp-Admin1'),
            'role'                => 'system_admin',
            'facility_id'         => null,
            'active'              => true,
            'password_changed_at' => null,
        ]);

        User::create([
            'username'            => 'manager1',
            'name'                => 'Dr. Jane Manager',
            'email'               => 'manager@vetops.local',
            'password'            => Hash::make('VetOps!Tmp-Mgr01'),
            'role'                => 'clinic_manager',
            'facility_id'         => $facility1->id,
            'active'              => true,
            'password_changed_at' => null,
        ]);

        User::create([
            'username'            => 'clerk1',
            'name'                => 'Bob Clerk',
            'email'               => 'clerk@vetops.local',
            'password'            => Hash::make('VetOps!Tmp-Clrk1'),
            'role'                => 'inventory_clerk',
            'facility_id'         => $facility1->id,
            'active'              => true,
            'password_changed_at' => null,
        ]);

        User::create([
            'username'            => 'doctor1',
            'name'                => 'Dr. Sarah Vet',
            'email'               => 'doctor@vetops.local',
            'password'            => Hash::make('VetOps!Tmp-Doc01'),
            'role'                => 'technician_doctor',
            'facility_id'         => $facility1->id,
            'active'              => true,
            'password_changed_at' => null,
        ]);

        User::create([
            'username'            => 'editor1',
            'name'                => 'Content Editor',
            'email'               => 'editor@vetops.local',
            'password'            => Hash::make('VetOps!Tmp-Edit1'),
            'role'                => 'content_editor',
            'facility_id'         => $facility1->id,
            'active'              => true,
            'password_changed_at' => null,
        ]);

        User::create([
            'username'            => 'approver1',
            'name'                => 'Content Approver',
            'email'               => 'approver@vetops.local',
            'password'            => Hash::make('VetOps!Tmp-Aprv1'),
            'role'                => 'content_approver',
            'facility_id'         => $facility1->id,
            'active'              => true,
            'password_changed_at' => null,
        ]);

        $storeroom1 = Storeroom::factory()->create(['facility_id' => $facility1->id, 'name' => 'Main Storage', 'code' => 'SR-001']);
        $storeroom2 = Storeroom::factory()->create(['facility_id' => $facility1->id, 'name' => 'Surgery Supply', 'code' => 'SR-002']);

        RentalAsset::factory()->count(5)->create(['facility_id' => $facility1->id]);
        RentalAsset::factory()->count(3)->create(['facility_id' => $facility2->id]);

        $items = InventoryItem::factory()->count(10)->create();

        Doctor::factory()->count(5)->create(['facility_id' => $facility1->id]);
        Patient::factory()->count(10)->create(['facility_id' => $facility1->id]);
    }
}
