<?php

namespace App\Queries;

use App\Services\MetricsService;

class GetTenantMetricsQuery
{
    public function execute(int $tenantId): array
    {
        return MetricsService::getDashboardMetrics($tenantId);
    }
}
