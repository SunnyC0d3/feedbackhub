<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JobMonitor
{
    public static function getFailedJobsCount(): int
    {
        return DB::table('failed_jobs')->count();
    }

    public static function getRecentFailedJobs(int $limit = 10): array
    {
        return DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($job) {
                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'payload' => json_decode($job->payload, true),
                    'exception' => substr($job->exception, 0, 200),
                    'failed_at' => $job->failed_at,
                ];
            })
            ->toArray();
    }

    public static function retryFailedJob(int $id): bool
    {
        try {
            $exitCode = \Artisan::call('queue:retry', ['id' => $id]);

            LogService::info('Failed job retry requested', [
                'job_id' => $id,
                'exit_code' => $exitCode,
                'event' => 'job_retry',
            ]);

            return $exitCode === 0;
        } catch (\Exception $e) {
            LogService::error('Failed to retry job', [
                'job_id' => $id,
                'error' => $e->getMessage(),
                'event' => 'job_retry_failed',
            ]);

            return false;
        }
    }

    public static function flushFailedJobs(): int
    {
        $count = DB::table('failed_jobs')->count();
        \Artisan::call('queue:flush');

        LogService::info('All failed jobs flushed', [
            'count' => $count,
            'event' => 'jobs_flushed',
        ]);

        return $count;
    }
}
