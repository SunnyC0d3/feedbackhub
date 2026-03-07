<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class DivisionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $compass = Tenant::where('slug', 'compass-group')->first();
        $acme = Tenant::where('slug', 'acme-corp')->first();

        Division::create([
            'tenant_id' => $compass->id,
            'name' => 'E-Foods',
            'slug' => 'compass-group-efoods',
        ]);

        Division::create([
            'tenant_id' => $compass->id,
            'name' => 'Bidfood',
            'slug' => 'compass-group-bidfood',
        ]);

        Division::create([
            'tenant_id' => $compass->id,
            'name' => 'Catering',
            'slug' => 'compass-group-catering',
        ]);

        Division::create([
            'tenant_id' => $acme->id,
            'name' => 'Sales',
            'slug' => 'acme-corp-sales',
        ]);

        Division::create([
            'tenant_id' => $acme->id,
            'name' => 'Engineering',
            'slug' => 'acme-corp-engineering',
        ]);

        Division::create([
            'tenant_id' => $acme->id,
            'name' => 'Marketing',
            'slug' => 'acme-corp-marketing',
        ]);

        $this->command->info('✅ Created 6 divisions');
    }
}
