<?php

namespace App\Jobs;

use App\Models\Invitation;
use App\Services\LogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupExpiredInvitations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 120;

    public function handle(): void
    {
        $startTime = microtime(true);

        LogService::jobStarted('CleanupExpiredInvitations');

        $deleted = Invitation::where('expires_at', '<', now()->subDay())
            ->delete();

        $duration = microtime(true) - $startTime;
        LogService::jobCompleted('CleanupExpiredInvitations', $duration, [
            'deleted_count' => $deleted,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        LogService::jobFailed('CleanupExpiredInvitations', $exception);
    }
}
