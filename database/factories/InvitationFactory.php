<?php

namespace Database\Factories;

use App\Models\Division;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'division_id' => Division::factory(),
            'invited_by_user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => fake()->randomElement(['admin', 'manager', 'member', 'support']),
            'token' => Str::random(64),
            'expires_at' => now()->addHours(48),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subHours(24)]);
    }
}
