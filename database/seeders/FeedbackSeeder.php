<?php

namespace Database\Seeders;

use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class FeedbackSeeder extends Seeder
{
    public function run(): void
    {
        Feedback::withoutEvents(function () {
            Project::all()->each(function (Project $project) {
                $users = User::where('tenant_id', $project->tenant_id)->get();

                if ($users->isEmpty()) {
                    return;
                }

                Feedback::factory()->count(rand(2, 3))->create([
                    'tenant_id' => $project->tenant_id,
                    'project_id' => $project->id,
                    'user_id' => fn () => $users->random()->id,
                ]);
            });
        });

        $this->command->info('✅ Created ' . Feedback::count() . ' feedback items');
    }
}
