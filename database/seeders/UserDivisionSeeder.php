<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserDivisionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['admin', 'manager', 'member', 'support'];
        $count = 0;

        Tenant::all()->each(function (Tenant $tenant) use ($roles, &$count) {
            $users = User::where('tenant_id', $tenant->id)->get();
            $divisions = Division::where('tenant_id', $tenant->id)->get();

            $firstUser = $users->first();
            $divisions->each(function (Division $division) use ($firstUser, &$count) {
                DB::table('user_divisions')->insert([
                    'user_id' => $firstUser->id,
                    'division_id' => $division->id,
                    'role' => 'admin',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            });

            $users->skip(1)->each(function (User $user) use ($divisions, $roles, &$count) {
                $assigned = $divisions->random(min(2, $divisions->count()));

                collect($assigned)->each(function (Division $division) use ($user, $roles, &$count) {
                    $exists = DB::table('user_divisions')
                        ->where('user_id', $user->id)
                        ->where('division_id', $division->id)
                        ->exists();

                    if (!$exists) {
                        DB::table('user_divisions')->insert([
                            'user_id' => $user->id,
                            'division_id' => $division->id,
                            'role' => $roles[array_rand($roles)],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $count++;
                    }
                });
            });
        });

        $this->command->info('✅ Created ' . $count . ' user-division assignments');
    }
}
