<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $compass = Tenant::where('slug', 'compass-group')->first();
        $acme = Tenant::where('slug', 'acme-corp')->first();

        User::create([
            'tenant_id' => $compass->id,
            'name' => 'Alice Admin',
            'email' => 'alice@compass.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        User::create([
            'tenant_id' => $compass->id,
            'name' => 'Bob Manager',
            'email' => 'bob@compass.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        User::create([
            'tenant_id' => $compass->id,
            'name' => 'Carol Member',
            'email' => 'carol@compass.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        User::create([
            'tenant_id' => $acme->id,
            'name' => 'David Admin',
            'email' => 'david@acme.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        User::create([
            'tenant_id' => $acme->id,
            'name' => 'Eve Manager',
            'email' => 'eve@acme.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $this->command->info('✅ Created 5 users (all passwords: password)');
    }
}
