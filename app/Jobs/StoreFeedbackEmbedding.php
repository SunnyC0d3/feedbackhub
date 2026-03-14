<?php

namespace App\Jobs;

use App\Models\Feedback;
use App\Services\{EmbeddingService, PineconeService, LogService};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StoreFeedbackEmbedding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $feedbackId;
    public $tries = 3;
    public $backoff = [60, 300, 900];

    public function __construct(int $feedbackId)
    {
        $this->feedbackId = $feedbackId;
    }

    public function handle(): void
    {
        $startTime = microtime(true);

        LogService::jobStarted('StoreFeedbackEmbedding', [
            'feedback_id' => $this->feedbackId,
        ]);

        $feedback = Feedback::find($this->feedbackId);

        if (!$feedback) {
            LogService::warning('Feedback not found for embedding', [
                'feedback_id' => $this->feedbackId,
            ]);
            return;
        }

        $embeddingService = app(EmbeddingService::class);
        $text = $feedback->title . ' ' . $feedback->description;
        $vector = $embeddingService->generateEmbedding($text);

        $pinecone = app(PineconeService::class);
        $pinecone->upsert([
            [
                'id' => "feedback-{$feedback->id}",
                'values' => $vector,
                'metadata' => [
                    'feedback_id' => $feedback->id,
                    'tenant_id' => $feedback->tenant_id,
                    'project_id' => $feedback->project_id,
                    'title' => $feedback->title,
                    'status' => $feedback->status,
                ]
            ]
        ]);

        $duration = microtime(true) - $startTime;
        LogService::jobCompleted('StoreFeedbackEmbedding', $duration, [
            'feedback_id' => $this->feedbackId,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        LogService::jobFailed('StoreFeedbackEmbedding', $exception, [
            'feedback_id' => $this->feedbackId,
        ]);
    }
}
