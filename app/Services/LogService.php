<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogService
{
    public static function getContext(array $extra = []): array
    {
        $context = [
            'request_id' => request()->header('X-Request-ID') ?? Str::uuid()->toString(),
            'timestamp' => now()->toIso8601String(),
        ];

        if (auth()->check()) {
            $context['tenant_id'] = auth()->user()->tenant_id;
            $context['user_id'] = auth()->id();
            $context['user_email'] = auth()->user()->email;
        }

        if (request()->route()) {
            $context['route'] = request()->route()->getName() ?? request()->path();
            $context['method'] = request()->method();
            $context['ip'] = request()->ip();
        }

        return array_merge($context, $extra);
    }

    public static function info(string $message, array $context = []): void
    {
        Log::info($message, self::getContext($context));
    }

    public static function warning(string $message, array $context = []): void
    {
        Log::warning($message, self::getContext($context));
    }

    public static function error(string $message, array $context = []): void
    {
        Log::error($message, self::getContext($context));
    }

    public static function debug(string $message, array $context = []): void
    {
        Log::debug($message, self::getContext($context));
    }

    public static function performance(string $operation, float $duration, array $context = []): void
    {
        self::info("Performance: {$operation}", array_merge($context, [
            'operation' => $operation,
            'duration_ms' => round($duration * 1000, 2),
            'duration_seconds' => round($duration, 4),
        ]));
    }

    public static function query(string $sql, array $bindings, float $time): void
    {
        if ($time > 100) {
            self::warning('Slow query detected', [
                'sql' => $sql,
                'bindings' => $bindings,
                'time_ms' => $time,
                'type' => 'slow_query',
            ]);
        }
    }

    public static function jobStarted(string $jobName, array $context = []): void
    {
        self::info("Job started: {$jobName}", array_merge($context, [
            'job_name' => $jobName,
            'event' => 'job_started',
        ]));
    }

    public static function jobCompleted(string $jobName, float $duration, array $context = []): void
    {
        self::info("Job completed: {$jobName}", array_merge($context, [
            'job_name' => $jobName,
            'duration_ms' => round($duration * 1000, 2),
            'event' => 'job_completed',
        ]));
    }

    public static function jobFailed(string $jobName, \Throwable $exception, array $context = []): void
    {
        self::error("Job failed: {$jobName}", array_merge($context, [
            'job_name' => $jobName,
            'error' => $exception->getMessage(),
            'error_class' => get_class($exception),
            'stack_trace' => $exception->getTraceAsString(),
            'event' => 'job_failed',
        ]));

        $failedCount = JobMonitor::getFailedJobsCount();

        if ($failedCount > 10) {
            self::error("High failed job count detected", [
                'failed_jobs_count' => $failedCount,
                'threshold' => 10,
                'event' => 'high_failure_rate_alert',
            ]);
        }
    }

    public static function apiCall(string $provider, string $endpoint, float $duration, array $context = []): void
    {
        self::info("API call: {$provider}", array_merge($context, [
            'provider' => $provider,
            'endpoint' => $endpoint,
            'duration_ms' => round($duration * 1000, 2),
            'event' => 'api_call',
        ]));
    }

    public static function apiError(string $provider, string $endpoint, \Throwable $exception, array $context = []): void
    {
        self::error("API error: {$provider}", array_merge($context, [
            'provider' => $provider,
            'endpoint' => $endpoint,
            'error' => $exception->getMessage(),
            'event' => 'api_error',
        ]));
    }

    public static function getSystemHealth(): array
    {
        $health = [
            'timestamp' => now()->toIso8601String(),
            'failed_jobs_count' => JobMonitor::getFailedJobsCount(),
        ];

        self::info('System health check', array_merge($health, [
            'event' => 'health_check',
        ]));

        return $health;
    }

    public static function retryFailedJob(int $jobId): bool
    {
        self::info('Attempting to retry failed job', [
            'job_id' => $jobId,
            'event' => 'job_retry_attempt',
        ]);

        $success = JobMonitor::retryFailedJob($jobId);

        if ($success) {
            self::info('Failed job retry successful', [
                'job_id' => $jobId,
                'event' => 'job_retry_success',
            ]);
        } else {
            self::error('Failed job retry failed', [
                'job_id' => $jobId,
                'event' => 'job_retry_failed',
            ]);
        }

        return $success;
    }
}
