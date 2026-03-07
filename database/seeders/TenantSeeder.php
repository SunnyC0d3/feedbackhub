<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::create([
            'name' => 'Compass Group',
            'slug' => 'compass-group',
            'active' => true,
        ]);

        Tenant::create([
            'name' => 'Acme Corporation',
            'slug' => 'acme-corp',
            'active' => true,
        ]);

        $this->command->info('✅ Created 2 tenants');
    }
}
