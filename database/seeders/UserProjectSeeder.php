<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserProjectSeeder extends Seeder
{
    public function run(): void
    {
        $carol = User::where('email', 'carol@compass.com')->first();
        $bob = User::where('email', 'bob@compass.com')->first();

        $mobileApp = Project::where('slug', 'compass-group-efoods-mobile-app')->first();
        $websiteRedesign = Project::where('slug', 'compass-group-efoods-website-redesign')->first();

        DB::table('user_projects')->insert([
            [
                'user_id' => $carol->id,
                'project_id' => $mobileApp->id,
                'assigned_by_user_id' => $bob->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $carol->id,
                'project_id' => $websiteRedesign->id,
                'assigned_by_user_id' => $bob->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->command->info('✅ Created user-project assignments');
    }
}
