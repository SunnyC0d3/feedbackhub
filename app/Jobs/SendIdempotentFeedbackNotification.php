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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SendIdempotentFeedbackNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $feedbackId;
    public $tries = 3;
    public $backoff = [60, 300, 900];
    public $timeout = 60;

    public function __construct(int $feedbackId)
    {
        $this->feedbackId = $feedbackId;
    }

    public function handle(): void
    {
        $startTime = microtime(true);

        LogService::jobStarted('SendIdempotentNotification', [
            'feedback_id' => $this->feedbackId,
            'attempt' => $this->attempts(),
        ]);

        $idempotencyKey = "notification:sent:{$this->feedbackId}";

        if (Cache::has($idempotencyKey)) {
            Log::info('Notification already sent, skipping', [
                'feedback_id' => $this->feedbackId,
            ]);
            return;
        }

        DB::transaction(function () use ($idempotencyKey, $startTime) {
            $feedback = Feedback::find($this->feedbackId);

            if (!$feedback) {
                LogService::warning('Feedback not found', [
                    'feedback_id' => $this->feedbackId,
                ]);
                return;
            }

            if (!in_array($feedback->status, ['draft', 'seen', 'pending'])) {
                LogService::info('Feedback status changed, skipping notification', [
                    'feedback_id' => $this->feedbackId,
                    'status' => $feedback->status,
                ]);
                return;
            }

            $this->sendNotification($feedback);

            Cache::put($idempotencyKey, true, 86400);

            $duration = microtime(true) - $startTime;
            LogService::jobCompleted('SendIdempotentNotification', $duration, [
                'feedback_id' => $this->feedbackId,
                'attempt' => $this->attempts(),
            ]);
        });
    }

    private function sendNotification(Feedback $feedback): void
    {
        $managers = $feedback->project
            ->division
            ->users()
            ->wherePivot('role', 'manager')
            ->get();

        foreach ($managers as $manager) {
            LogService::info('Sending notification', [
                'feedback_id' => $feedback->id,
                'manager_id' => $manager->id,
                'manager_email' => $manager->email,
                'notification_type' => 'feedback_created',
            ]);

            // Mail::to($manager)->send(...);
        }
    }

    public function failed(\Throwable $exception): void
    {
        LogService::jobFailed('SendIdempotentNotification', $exception, [
            'feedback_id' => $this->feedbackId,
        ]);
    }
}
