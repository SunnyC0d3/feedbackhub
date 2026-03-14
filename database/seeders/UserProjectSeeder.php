<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserProjectSeeder extends Seeder
{
    public function run(): void
    {
        $count = 0;

        Tenant::all()->each(function (Tenant $tenant) use (&$count) {
            $users = User::where('tenant_id', $tenant->id)->get();
            $projects = Project::where('tenant_id', $tenant->id)->get();
            $assigner = $users->first();

            $users->skip(1)->each(function (User $user) use ($projects, $assigner, &$count) {
                $assigned = $projects->random(min(2, $projects->count()));

                collect($assigned)->each(function (Project $project) use ($user, $assigner, &$count) {
                    $exists = DB::table('user_projects')
                        ->where('user_id', $user->id)
                        ->where('project_id', $project->id)
                        ->exists();

                    if (!$exists) {
                        DB::table('user_projects')->insert([
                            'user_id' => $user->id,
                            'project_id' => $project->id,
                            'assigned_by_user_id' => $assigner->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $count++;
                    }
                });
            });
        });

        $this->command->info('✅ Created ' . $count . ' user-project assignments');
    }
}
