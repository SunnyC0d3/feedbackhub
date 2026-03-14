<?php

namespace App\Services;

use App\Models\Feedback;
use Illuminate\Support\Collection;

class FeedbackAnalysisService
{
    public function __construct(
        private EmbeddingService $embeddingService,
        private PineconeService $pineconeService,
        private AIService $aiService,
    ) {}

    public function analyzeByQuery(string $query, int $tenantId, int $topK = 10): array
    {
        LogService::info('Feedback analysis started', [
            'query' => $query,
            'tenant_id' => $tenantId,
            'top_k' => $topK,
            'event' => 'analysis_started',
        ]);

        $queryVector = $this->embeddingService->generateEmbedding($query);

        $results = $this->pineconeService->query($queryVector, $topK, ['tenant_id' => $tenantId]);
        $matches = $results['matches'] ?? [];

        if (empty($matches)) {
            LogService::info('Feedback analysis found no matches', [
                'query' => $query,
                'tenant_id' => $tenantId,
                'event' => 'analysis_no_matches',
            ]);

            return [
                'query' => $query,
                'feedback_found' => 0,
                'matches' => [],
                'feedback' => collect(),
                'summary' => null,
                'tokens_used' => null,
                'cost_usd' => null,
            ];
        }

        $feedbackIds = collect($matches)
            ->pluck('metadata.feedback_id')
            ->filter()
            ->values()
            ->toArray();

        $feedback = Feedback::whereIn('id', $feedbackIds)->get();

        if ($feedback->isEmpty()) {
            return [
                'query' => $query,
                'feedback_found' => 0,
                'matches' => $matches,
                'feedback' => collect(),
                'summary' => null,
                'tokens_used' => null,
                'cost_usd' => null,
            ];
        }

        $summaryResult = $this->aiService->summarizeFeedback($feedback->toArray(), $tenantId);

        LogService::info('Feedback analysis completed', [
            'query' => $query,
            'tenant_id' => $tenantId,
            'feedback_found' => $feedback->count(),
            'tokens_used' => $summaryResult['tokens_used'],
            'cost_usd' => $summaryResult['cost_usd'],
            'event' => 'analysis_completed',
        ]);

        return [
            'query' => $query,
            'feedback_found' => $feedback->count(),
            'matches' => $matches,
            'feedback' => $feedback,
            'summary' => $summaryResult['summary'],
            'tokens_used' => $summaryResult['tokens_used'],
            'cost_usd' => $summaryResult['cost_usd'],
        ];
    }

    public function summarize(Collection $feedback, int $tenantId): array
    {
        if ($feedback->isEmpty()) {
            return [
                'feedback_found' => 0,
                'summary' => null,
                'tokens_used' => null,
                'cost_usd' => null,
            ];
        }

        $result = $this->aiService->summarizeFeedback($feedback->toArray(), $tenantId);

        return [
            'feedback_found' => $feedback->count(),
            'summary' => $result['summary'],
            'tokens_used' => $result['tokens_used'],
            'cost_usd' => $result['cost_usd'],
        ];
    }
}
