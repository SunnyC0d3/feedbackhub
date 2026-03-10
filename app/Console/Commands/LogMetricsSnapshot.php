<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\MetricsService;
use Illuminate\Console\Command;

class LogMetricsSnapshot extends Command
{
    protected $signature = 'metrics:snapshot';
    protected $description = 'Log metrics snapshot for all tenants';

    public function handle(): int
    {
        $this->info('Logging metrics snapshots...');

        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            MetricsService::logMetricsSnapshot($tenant->id);
            $this->info("Logged snapshot for tenant: {$tenant->name}");
        }

        $this->info('Metrics snapshots logged successfully!');

        return 0;
    }
}
