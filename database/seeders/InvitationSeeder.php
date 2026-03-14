<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class InvitationSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::all()->each(function (Tenant $tenant) {
            $inviter = User::where('tenant_id', $tenant->id)->first();
            $division = Division::where('tenant_id', $tenant->id)->first();

            if (!$inviter || !$division) {
                return;
            }

            Invitation::factory()->create([
                'tenant_id' => $tenant->id,
                'division_id' => $division->id,
                'invited_by_user_id' => $inviter->id,
            ]);

            Invitation::factory()->expired()->create([
                'tenant_id' => $tenant->id,
                'division_id' => $division->id,
                'invited_by_user_id' => $inviter->id,
            ]);
        });

        $this->command->info('✅ Created ' . Invitation::count() . ' invitations');
    }
}
