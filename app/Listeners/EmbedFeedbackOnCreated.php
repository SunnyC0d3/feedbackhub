<?php

namespace App\Listeners;

use App\Events\FeedbackCreated;
use App\Jobs\StoreFeedbackEmbedding;
use App\Services\LogService;

class EmbedFeedbackOnCreated
{
    public function handle(FeedbackCreated $event): void
    {
        StoreFeedbackEmbedding::dispatch($event->feedback->id);

        LogService::info('Feedback embedding job dispatched', [
            'feedback_id' => $event->feedback->id,
            'job' => 'StoreFeedbackEmbedding',
            'event' => 'embedding_job_dispatched',
        ]);
    }
}
