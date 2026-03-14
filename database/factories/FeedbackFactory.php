<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeedbackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => fake()->randomElement(['draft', 'seen', 'pending', 'review_required', 'closed']),
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }

    public function closed(): static
    {
        return $this->state(['status' => 'closed']);
    }
}
