<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }

    public function compassGroup(): static
    {
        return $this->state(['name' => 'Compass Group', 'slug' => 'compass-group']);
    }

    public function acmeCorp(): static
    {
        return $this->state(['name' => 'Acme Corporation', 'slug' => 'acme-corp']);
    }
}
