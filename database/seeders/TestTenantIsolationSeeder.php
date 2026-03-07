<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestTenantIsolationSeeder extends Seeder
{
    public function run(): void
    {
        $compassTenant = Tenant::create([
            'name' => 'Compass Group',
            'slug' => 'compass-group',
            'active' => true,
        ]);

        $acmeTenant = Tenant::create([
            'name' => 'Acme Corporation',
            'slug' => 'acme-corp',
            'active' => true,
        ]);

        $compassUser = User::create([
            'tenant_id' => $compassTenant->id,
            'name' => 'Alice Smith',
            'email' => 'alice@compass.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $acmeUser = User::create([
            'tenant_id' => $acmeTenant->id,
            'name' => 'Bob Jones',
            'email' => 'bob@acme.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        Division::create([
            'tenant_id' => $compassTenant->id,
            'name' => 'FoodBuy',
            'slug' => 'foodbuy',
        ]);

        Division::create([
            'tenant_id' => $compassTenant->id,
            'name' => 'E-Foods',
            'slug' => 'e-foods',
        ]);

        Division::create([
            'tenant_id' => $acmeTenant->id,
            'name' => 'Sales',
            'slug' => 'sales',
        ]);

        Division::create([
            'tenant_id' => $acmeTenant->id,
            'name' => 'Engineering',
            'slug' => 'engineering',
        ]);

        $this->command->info('✅ Test data created!');
        $this->command->info('Compass Tenant ID: ' . $compassTenant->id);
        $this->command->info('Acme Tenant ID: ' . $acmeTenant->id);
    }
}
