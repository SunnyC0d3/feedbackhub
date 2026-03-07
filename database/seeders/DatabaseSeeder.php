<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TenantSeeder::class,
            UserSeeder::class,
            DivisionSeeder::class,
            ProjectSeeder::class,
            FeedbackSeeder::class,
            UserDivisionSeeder::class,
            UserProjectSeeder::class,
            InvitationSeeder::class,
        ]);
    }
}
