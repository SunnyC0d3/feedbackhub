<?php

namespace App\Jobs;

use App\Services\LogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TestFailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [10, 30, 60];

    public function __construct()
    {
    }

    public function handle(): void
    {
        $startTime = microtime(true);

        LogService::jobStarted('TestFailingJob', [
            'attempt' => $this->attempts(),
        ]);

        if ($this->attempts() < 3) {
            LogService::warning('Simulating failure', [
                'attempt' => $this->attempts(),
                'reason' => 'testing_retry_logic',
            ]);

            throw new \Exception('Simulated external API failure');
        }

        $duration = microtime(true) - $startTime;
        LogService::jobCompleted('TestFailingJob', $duration, [
            'attempt' => $this->attempts(),
            'outcome' => 'succeeded_on_retry',
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        LogService::jobFailed('TestFailingJob', $exception, [
            'attempts' => $this->attempts(),
        ]);
    }
}
