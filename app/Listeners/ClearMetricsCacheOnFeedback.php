<?php

namespace App\Listeners;

use App\Events\FeedbackCreated;
use App\Events\FeedbackStatusChanged;
use App\Services\MetricsService;

class ClearMetricsCacheOnFeedback
{
    public function handleCreated(FeedbackCreated $event): void
    {
        MetricsService::clearMetricsCache($event->feedback->tenant_id);
    }

    public function handleStatusChanged(FeedbackStatusChanged $event): void
    {
        MetricsService::clearMetricsCache($event->feedback->tenant_id);
    }
}
