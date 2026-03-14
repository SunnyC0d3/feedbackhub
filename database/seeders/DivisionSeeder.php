<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class DivisionSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::all()->each(function (Tenant $tenant) {
            Division::factory()->count(3)->create(['tenant_id' => $tenant->id]);
        });

        $this->command->info('✅ Created ' . Division::count() . ' divisions');
    }
}
