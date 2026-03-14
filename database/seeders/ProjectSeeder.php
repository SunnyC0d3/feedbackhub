<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        Division::all()->each(function (Division $division) {
            Project::factory()->count(2)->create([
                'tenant_id' => $division->tenant_id,
                'division_id' => $division->id,
            ]);
        });

        $this->command->info('✅ Created ' . Project::count() . ' projects');
    }
}
