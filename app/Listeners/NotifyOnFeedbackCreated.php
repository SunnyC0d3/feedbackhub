<?php

namespace App\Listeners;

use App\Events\FeedbackCreated;
use App\Jobs\SendIdempotentFeedbackNotification;
use App\Services\LogService;

class NotifyOnFeedbackCreated
{
    public function handle(FeedbackCreated $event): void
    {
        SendIdempotentFeedbackNotification::dispatch($event->feedback->id);

        LogService::info('Feedback notification job dispatched', [
            'feedback_id' => $event->feedback->id,
            'job' => 'SendIdempotentFeedbackNotification',
            'event' => 'job_dispatched',
        ]);
    }
}
