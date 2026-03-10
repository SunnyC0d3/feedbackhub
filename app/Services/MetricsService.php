<?php

namespace App\Services;

use App\Models\{Feedback, Project, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class MetricsService
{
    const CACHE_TTL = 300;

    public static function getDashboardMetrics(int $tenantId): array
    {
        $cacheKey = "metrics:dashboard:{$tenantId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId) {
            $startTime = microtime(true);

            $metrics = [
                'tenant_id' => $tenantId,
                'timestamp' => now()->toIso8601String(),

                'total_feedback' => Feedback::where('tenant_id', $tenantId)->count(),
                'total_projects' => Project::where('tenant_id', $tenantId)->count(),
                'total_users' => User::where('tenant_id', $tenantId)->count(),

                'feedback_by_status' => self::getFeedbackByStatus($tenantId),

                'feedback_today' => Feedback::where('tenant_id', $tenantId)
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),

                'feedback_this_week' => Feedback::where('tenant_id', $tenantId)
                    ->where('created_at', '>=', now()->subWeek())
                    ->count(),

                'failed_jobs' => JobMonitor::getFailedJobsCount(),
            ];

            $duration = microtime(true) - $startTime;
            LogService::performance('dashboard_metrics', $duration, [
                'tenant_id' => $tenantId,
                'metrics_cached' => false,
            ]);

            return $metrics;
        });
    }

    private static function getFeedbackByStatus(int $tenantId): array
    {
        return Feedback::where('tenant_id', $tenantId)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    public static function getFeedbackTrends(int $tenantId, int $days = 7): array
    {
        $cacheKey = "metrics:feedback_trends:{$tenantId}:{$days}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId, $days) {
            $trends = [];

            for ($i = $days - 1; $i >= 0; $i--) {
                $date = now()->subDays($i)->startOfDay();
                $count = Feedback::where('tenant_id', $tenantId)
                    ->whereDate('created_at', $date)
                    ->count();

                $trends[] = [
                    'date' => $date->toDateString(),
                    'count' => $count,
                ];
            }

            return $trends;
        });
    }

    public static function getProjectStats(int $tenantId): array
    {
        $cacheKey = "metrics:project_stats:{$tenantId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId) {
            $projects = Project::where('tenant_id', $tenantId)
                ->withCount('feedbacks')
                ->get();

            return [
                'total_projects' => $projects->count(),
                'projects_with_feedback' => $projects->where('feedbacks_count', '>', 0)->count(),
                'avg_feedback_per_project' => round($projects->avg('feedbacks_count'), 2),
                'most_active_project' => $projects->sortByDesc('feedbacks_count')->first()?->only(['id', 'name', 'feedbacks_count']),
            ];
        });
    }

    public static function getUserActivity(int $tenantId): array
    {
        $cacheKey = "metrics:user_activity:{$tenantId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId) {
            $totalUsers = User::where('tenant_id', $tenantId)->count();

            $activeUsers = User::where('tenant_id', $tenantId)
                ->whereHas('feedbacks', function ($query) {
                    $query->where('created_at', '>=', now()->subWeek());
                })
                ->count();

            return [
                'total_users' => $totalUsers,
                'active_users_7d' => $activeUsers,
                'activity_rate' => $totalUsers > 0
                    ? round(($activeUsers / $totalUsers) * 100, 2)
                    : 0,
            ];
        });
    }

    public static function getSystemHealth(): array
    {
        $cacheKey = "metrics:system_health";

        return Cache::remember($cacheKey, 60, function () {
            $failedJobs = JobMonitor::getFailedJobsCount();

            $queueDepth = self::getQueueDepth();

            $healthScore = 100;
            if ($failedJobs > 0) $healthScore -= min($failedJobs * 5, 50);
            if ($queueDepth > 100) $healthScore -= 20;
            if ($queueDepth > 500) $healthScore -= 30;

            $status = 'healthy';
            if ($healthScore < 80) $status = 'degraded';
            if ($healthScore < 50) $status = 'unhealthy';

            return [
                'timestamp' => now()->toIso8601String(),
                'status' => $status,
                'health_score' => max(0, $healthScore),
                'failed_jobs_count' => $failedJobs,
                'queue_depth' => $queueDepth,
                'cache_status' => self::testCache(),
                'database_status' => self::testDatabase(),
            ];
        });
    }

    private static function getQueueDepth(): int
    {
        try {
            $redis = Cache::store('redis')->getRedis();
            return $redis->llen('laravel_database_queues:default') ?? 0;
        } catch (\Exception $e) {
            LogService::error('Failed to get queue depth', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private static function testCache(): string
    {
        try {
            Cache::put('health_check', true, 10);
            $result = Cache::get('health_check');
            return $result ? 'healthy' : 'degraded';
        } catch (\Exception $e) {
            LogService::error('Cache health check failed', [
                'error' => $e->getMessage(),
            ]);
            return 'unhealthy';
        }
    }

    private static function testDatabase(): string
    {
        try {
            DB::connection()->getPdo();
            return 'healthy';
        } catch (\Exception $e) {
            LogService::error('Database health check failed', [
                'error' => $e->getMessage(),
            ]);
            return 'unhealthy';
        }
    }

    public static function clearMetricsCache(int $tenantId = null): void
    {
        if ($tenantId) {
            Cache::forget("metrics:dashboard:{$tenantId}");
            Cache::forget("metrics:feedback_trends:{$tenantId}:7");
            Cache::forget("metrics:project_stats:{$tenantId}");
            Cache::forget("metrics:user_activity:{$tenantId}");

            LogService::info('Tenant metrics cache cleared', [
                'tenant_id' => $tenantId,
                'event' => 'cache_cleared',
            ]);
        } else {
            Cache::forget('metrics:system_health');

            LogService::info('System metrics cache cleared', [
                'event' => 'cache_cleared',
            ]);
        }
    }

    public static function logMetricsSnapshot(int $tenantId): void
    {
        $metrics = self::getDashboardMetrics($tenantId);

        LogService::info('Metrics snapshot', array_merge($metrics, [
            'event' => 'metrics_snapshot',
        ]));
    }
}
