<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();

        $counts = [3, 2];

        $tenants->each(function (Tenant $tenant, int $index) use ($counts) {
            User::factory()->count($counts[$index] ?? 2)->create(['tenant_id' => $tenant->id]);
        });

        $this->command->info('✅ Created ' . User::count() . ' users');
    }
}
