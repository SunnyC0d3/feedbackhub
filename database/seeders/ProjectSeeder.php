<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        $efoods = Division::where('slug', 'compass-group-efoods')->first();
        $bidfood = Division::where('slug', 'compass-group-bidfood')->first();
        $catering = Division::where('slug', 'compass-group-catering')->first();

        $sales = Division::where('slug', 'acme-corp-sales')->first();
        $engineering = Division::where('slug', 'acme-corp-engineering')->first();
        $marketing = Division::where('slug', 'acme-corp-marketing')->first();

        Project::create([
            'tenant_id' => $efoods->tenant_id,
            'division_id' => $efoods->id,
            'name' => 'Mobile App',
            'slug' => 'compass-group-efoods-mobile-app',
            'description' => 'Customer-facing mobile application for online food ordering and delivery tracking',
        ]);

        Project::create([
            'tenant_id' => $efoods->tenant_id,
            'division_id' => $efoods->id,
            'name' => 'Website Redesign',
            'slug' => 'compass-group-efoods-website-redesign',
            'description' => 'Complete overhaul of the e-commerce platform with improved UX and checkout flow',
        ]);

        Project::create([
            'tenant_id' => $bidfood->tenant_id,
            'division_id' => $bidfood->id,
            'name' => 'Mobile App',
            'slug' => 'compass-group-bidfood-mobile-app',
            'description' => 'B2B mobile app for wholesale food ordering and inventory management',
        ]);

        Project::create([
            'tenant_id' => $bidfood->tenant_id,
            'division_id' => $bidfood->id,
            'name' => 'Website Redesign',
            'slug' => 'compass-group-bidfood-website-redesign',
            'description' => 'Modernization of the supplier portal with enhanced catalog browsing',
        ]);

        Project::create([
            'tenant_id' => $catering->tenant_id,
            'division_id' => $catering->id,
            'name' => 'Mobile App',
            'slug' => 'compass-group-catering-mobile-app',
            'description' => 'Event catering booking app with menu customization and scheduling',
        ]);

        Project::create([
            'tenant_id' => $catering->tenant_id,
            'division_id' => $catering->id,
            'name' => 'Website Redesign',
            'slug' => 'compass-group-catering-website-redesign',
            'description' => 'Corporate catering website with real-time availability and booking system',
        ]);

        Project::create([
            'tenant_id' => $sales->tenant_id,
            'division_id' => $sales->id,
            'name' => 'Mobile App',
            'slug' => 'acme-corp-sales-mobile-app',
            'description' => 'Field sales mobile CRM for lead tracking and customer visits',
        ]);

        Project::create([
            'tenant_id' => $sales->tenant_id,
            'division_id' => $sales->id,
            'name' => 'Website Redesign',
            'slug' => 'acme-corp-sales-website-redesign',
            'description' => 'Sales team portal with pipeline visualization and reporting dashboards',
        ]);

        Project::create([
            'tenant_id' => $engineering->tenant_id,
            'division_id' => $engineering->id,
            'name' => 'Mobile App',
            'slug' => 'acme-corp-engineering-mobile-app',
            'description' => 'Internal tools mobile app for bug reporting and feature requests',
        ]);

        Project::create([
            'tenant_id' => $engineering->tenant_id,
            'division_id' => $engineering->id,
            'name' => 'Website Redesign',
            'slug' => 'acme-corp-engineering-website-redesign',
            'description' => 'Developer documentation hub with API reference and code examples',
        ]);

        Project::create([
            'tenant_id' => $marketing->tenant_id,
            'division_id' => $marketing->id,
            'name' => 'Mobile App',
            'slug' => 'acme-corp-marketing-mobile-app',
            'description' => 'Campaign management app for content scheduling and analytics',
        ]);

        Project::create([
            'tenant_id' => $marketing->tenant_id,
            'division_id' => $marketing->id,
            'name' => 'Website Redesign',
            'slug' => 'acme-corp-marketing-website-redesign',
            'description' => 'Marketing website with landing page builder and A/B testing capabilities',
        ]);

        $this->command->info('✅ Created 12 projects');
    }
}
