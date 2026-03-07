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
        $projects = Project::all();

        if ($projects->isEmpty()) {
            $this->command->warn('No projects found, skipping feedback seeding');
            return;
        }

        $compassUsers = User::where('tenant_id', 1)->get();
        $acmeUsers = User::where('tenant_id', 2)->get();

        $statuses = ['draft', 'seen', 'pending', 'review_required', 'closed'];

        $feedbackTemplates = [
            ['title' => 'Login button not responsive', 'description' => 'The login button does not respond when clicked on iOS devices'],
            ['title' => 'App crashes on image upload', 'description' => 'Application crashes when trying to upload images larger than 5MB'],
            ['title' => 'Slow page load times', 'description' => 'Homepage takes over 5 seconds to load on mobile connections'],
            ['title' => 'Search returns incorrect results', 'description' => 'Search functionality is not filtering products correctly'],
            ['title' => 'Broken link in footer', 'description' => 'The "About Us" link in footer returns 404 error'],
            ['title' => 'Push notifications not working', 'description' => 'Users are not receiving push notifications for new orders'],
        ];

        foreach ($projects as $project) {
            $users = $project->tenant_id == 1 ? $compassUsers : $acmeUsers;

            if ($users->isEmpty()) {
                continue;
            }

            $feedbackCount = rand(2, 3);

            for ($i = 0; $i < $feedbackCount; $i++) {
                $template = $feedbackTemplates[array_rand($feedbackTemplates)];

                Feedback::create([
                    'tenant_id' => $project->tenant_id,
                    'project_id' => $project->id,
                    'user_id' => $users->random()->id,
                    'title' => $template['title'],
                    'description' => $template['description'],
                    'status' => $statuses[array_rand($statuses)],
                ]);
            }
        }

        $this->command->info('✅ Created ' . Feedback::count() . ' feedback items');
    }
}
