<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use OpenAI;

class AIService
{
    private $client;
    private string $model = 'gpt-4o-mini';

    public function __construct()
    {
        $this->client = OpenAI::client(config('services.openai.api_key'));
    }

    public function summarizeFeedback(array $feedbackItems, int $tenantId): array
    {
        $startTime = microtime(true);

        $feedbackText = $this->prepareFeedbackText($feedbackItems);

        try {
            $response = $this->client->chat()->create([
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful assistant that analyzes customer feedback. Provide concise, actionable summaries.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->buildSummaryPrompt($feedbackText)
                    ]
                ],
                'max_tokens' => 500,
                'temperature' => 0.3,
            ]);

            $summary = $response->choices[0]->message->content;
            $tokensUsed = $response->usage->totalTokens;
            $duration = microtime(true) - $startTime;

            $cost = $this->calculateCost($response->usage->promptTokens, $response->usage->completionTokens);

            LogService::apiCall('openai', 'chat/completions', $duration, [
                'model' => $this->model,
                'feedback_count' => count($feedbackItems),
                'tokens_used' => $tokensUsed,
                'cost_usd' => $cost,
                'tenant_id' => $tenantId,
            ]);

            $this->trackUsage($tenantId, $tokensUsed, $cost);

            return [
                'summary' => $summary,
                'tokens_used' => $tokensUsed,
                'cost_usd' => $cost,
                'feedback_count' => count($feedbackItems),
            ];

        } catch (\Exception $e) {
            LogService::apiError('openai', 'chat/completions', $e, [
                'feedback_count' => count($feedbackItems),
                'tenant_id' => $tenantId,
            ]);

            throw $e;
        }
    }

    private function prepareFeedbackText(array $feedbackItems): string
    {
        $text = '';

        foreach ($feedbackItems as $index => $feedback) {
            $text .= sprintf(
                "Feedback #%d:\nTitle: %s\nDescription: %s\nStatus: %s\n\n",
                $index + 1,
                $feedback['title'] ?? $feedback->title,
                $feedback['description'] ?? $feedback->description,
                $feedback['status'] ?? $feedback->status
            );
        }

        return $text;
    }

    private function buildSummaryPrompt(string $feedbackText): string
    {
        return <<<PROMPT
            Please analyze the following customer feedback and provide:

            1. **Key Themes**: What are the main issues or topics mentioned?
            2. **Critical Issues**: What problems need immediate attention?
            3. **Positive Feedback**: What's working well?
            4. **Recommendations**: What should be prioritized?

            Keep the summary concise and actionable.

            FEEDBACK:
            {$feedbackText}
            PROMPT;
    }

    private function calculateCost(int $inputTokens, int $outputTokens): float
    {
        $inputCost = ($inputTokens / 1_000_000) * 0.150;
        $outputCost = ($outputTokens / 1_000_000) * 0.600;

        return round($inputCost + $outputCost, 6);
    }

    private function trackUsage(int $tenantId, int $tokens, float $cost): void
    {
        $date = now()->format('Y-m-d');
        $cacheKey = "ai_usage:{$tenantId}:{$date}";

        $usage = Cache::get($cacheKey);

        if (!$usage) {
            $usage = [
                'date' => $date,
                'tenant_id' => $tenantId,
                'total_tokens' => 0,
                'total_cost' => 0.0,
                'requests' => 0,
            ];
        }

        $usage['total_tokens'] += $tokens;
        $usage['total_cost'] = round($usage['total_cost'] + $cost, 6);
        $usage['requests'] += 1;

        Cache::put($cacheKey, $usage, 86400 * 7);

        LogService::info('AI usage tracked', [
            'tenant_id' => $tenantId,
            'tokens' => $tokens,
            'cost' => $cost,
            'daily_total_tokens' => $usage['total_tokens'],
            'daily_total_cost' => $usage['total_cost'],
            'daily_requests' => $usage['requests'],
            'event' => 'ai_usage_tracked',
        ]);
    }

    public function getUsageStats(int $tenantId, int $days = 7): array
    {
        $stats = [];

        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i)->format('Y-m-d');
            $cacheKey = "ai_usage:{$tenantId}:{$date}";

            $data = Cache::get($cacheKey);

            if ($data) {
                $stats[] = $data;
            }
        }

        return $stats;
    }

    public function checkUsageLimits(int $tenantId, float $dailyLimit = 1.00): bool
    {
        $date = now()->format('Y-m-d');
        $cacheKey = "ai_usage:{$tenantId}:{$date}";

        $usage = Cache::get($cacheKey);

        if (!$usage) {
            return true;
        }

        if ($usage['total_cost'] >= $dailyLimit) {
            LogService::warning('AI usage limit exceeded', [
                'tenant_id' => $tenantId,
                'daily_cost' => $usage['total_cost'],
                'daily_limit' => $dailyLimit,
                'event' => 'ai_usage_limit_exceeded',
            ]);

            return false;
        }

        return true;
    }
}
