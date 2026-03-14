<?php

namespace Database\Factories;

use App\Models\Division;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->catchPhrase();

        return [
            'tenant_id' => Tenant::factory(),
            'division_id' => Division::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
        ];
    }
}
