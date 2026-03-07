<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InvitationSeeder extends Seeder
{
    public function run(): void
    {
        $alice = User::where('email', 'alice@compass.com')->first();
        $david = User::where('email', 'david@acme.com')->first();

        $efoods = Division::where('slug', 'compass-group-efoods')->first();
        $sales = Division::where('slug', 'acme-corp-sales')->first();

        Invitation::create([
            'tenant_id' => $alice->tenant_id,
            'division_id' => $efoods->id,
            'invited_by_user_id' => $alice->id,
            'email' => 'newuser@compass.com',
            'role' => 'member',
            'token' => Str::random(64),
            'expires_at' => now()->addHours(48),
        ]);

        Invitation::create([
            'tenant_id' => $alice->tenant_id,
            'division_id' => $efoods->id,
            'invited_by_user_id' => $alice->id,
            'email' => 'olduser@compass.com',
            'role' => 'member',
            'token' => Str::random(64),
            'expires_at' => now()->subHours(24),
        ]);

        Invitation::create([
            'tenant_id' => $david->tenant_id,
            'division_id' => $sales->id,
            'invited_by_user_id' => $david->id,
            'email' => 'newuser@acme.com',
            'role' => 'manager',
            'token' => Str::random(64),
            'expires_at' => now()->addHours(48),
        ]);

        $this->command->info('✅ Created 3 invitations');
    }
}
