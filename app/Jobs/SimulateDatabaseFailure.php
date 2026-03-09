<?php

namespace App\Jobs;

use App\Models\Feedback;
use App\Services\LogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SimulateDatabaseFailure implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [10, 30, 60];

    public function handle(): void
    {
        $startTime = microtime(true);

        LogService::jobStarted('SimulateDatabaseFailure', [
            'attempt' => $this->attempts(),
        ]);

        try {
            DB::transaction(function () use ($startTime) {
                $feedback = Feedback::create([
                    'tenant_id' => 7,
                    'project_id' => 37,
                    'user_id' => 16,
                    'title' => 'Transaction Test',
                    'description' => 'Testing rollback',
                    'status' => 'draft',
                ]);

                LogService::info('Feedback created in transaction', [
                    'feedback_id' => $feedback->id,
                    'attempt' => $this->attempts(),
                ]);

                if ($this->attempts() < 2) {
                    throw new \Exception('Simulated failure after DB write');
                }

                $duration = microtime(true) - $startTime;
                LogService::jobCompleted('SimulateDatabaseFailure', $duration, [
                    'feedback_id' => $feedback->id,
                    'outcome' => 'transaction_committed',
                ]);
            });
        } catch (\Exception $e) {
            LogService::error('Transaction rolled back', [
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'event' => 'transaction_rollback',
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        LogService::jobFailed('SimulateDatabaseFailure', $exception);
    }
}
