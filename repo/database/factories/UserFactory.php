<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'username'             => $this->faker->unique()->userName(),
            'name'                 => $this->faker->name(),
            'email'                => $this->faker->unique()->safeEmail(),
            'password'             => static::$password ??= Hash::make('Password123!'),
            'role'                 => 'technician_doctor',
            'facility_id'          => null,
            'department_id'        => null,
            'active'               => true,
            'password_changed_at'  => now(),
            'inactivity_timeout'   => 15,
            'remember_token'       => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => 'system_admin']);
    }

    public function manager(): static
    {
        return $this->state(['role' => 'clinic_manager']);
    }

    public function inventoryClerk(): static
    {
        return $this->state(['role' => 'inventory_clerk']);
    }

    public function contentEditor(): static
    {
        return $this->state(['role' => 'content_editor']);
    }

    public function contentApprover(): static
    {
        return $this->state(['role' => 'content_approver']);
    }
}
