<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserDivisionSeeder extends Seeder
{
    public function run(): void
    {
        $alice = User::where('email', 'alice@compass.com')->first();
        $bob = User::where('email', 'bob@compass.com')->first();
        $carol = User::where('email', 'carol@compass.com')->first();
        $david = User::where('email', 'david@acme.com')->first();
        $eve = User::where('email', 'eve@acme.com')->first();

        $efoods = Division::where('slug', 'compass-group-efoods')->first();
        $bidfood = Division::where('slug', 'compass-group-bidfood')->first();
        $catering = Division::where('slug', 'compass-group-catering')->first();
        $sales = Division::where('slug', 'acme-corp-sales')->first();
        $engineering = Division::where('slug', 'acme-corp-engineering')->first();
        $marketing = Division::where('slug', 'acme-corp-marketing')->first();

        DB::table('user_divisions')->insert([
            ['user_id' => $alice->id, 'division_id' => $efoods->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $alice->id, 'division_id' => $bidfood->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $alice->id, 'division_id' => $catering->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('user_divisions')->insert([
            ['user_id' => $bob->id, 'division_id' => $efoods->id, 'role' => 'manager', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $bob->id, 'division_id' => $bidfood->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('user_divisions')->insert([
            ['user_id' => $carol->id, 'division_id' => $efoods->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('user_divisions')->insert([
            ['user_id' => $david->id, 'division_id' => $sales->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $david->id, 'division_id' => $engineering->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $david->id, 'division_id' => $marketing->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('user_divisions')->insert([
            ['user_id' => $eve->id, 'division_id' => $sales->id, 'role' => 'manager', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->command->info('✅ Created user-division assignments');
    }
}
