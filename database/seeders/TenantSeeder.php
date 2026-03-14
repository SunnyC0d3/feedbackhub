<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::factory()->compassGroup()->create();
        Tenant::factory()->acmeCorp()->create();

        $this->command->info('✅ Created 2 tenants');
    }
}
