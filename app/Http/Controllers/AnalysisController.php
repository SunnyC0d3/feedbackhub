<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyzeFeedbackRequest;
use App\Http\Resources\AnalysisResource;
use App\Repositories\FeedbackRepository;
use App\Services\AIService;
use App\Services\FeedbackAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalysisController extends Controller
{
    public function __construct(
        private FeedbackAnalysisService $analysis,
        private FeedbackRepository $repository,
        private AIService $ai,
    ) {}

    public function query(AnalyzeFeedbackRequest $request): AnalysisResource
    {
        $result = $this->analysis->analyzeByQuery(
            query:    $request->string('query'),
            tenantId: $request->user()->tenant_id,
            topK:     $request->integer('top_k', 10),
        );

        return new AnalysisResource($result);
    }

    public function summarizeProject(Request $request, int $projectId): JsonResponse
    {
        $feedback = $this->repository->findByProject($projectId);

        abort_if($feedback->isEmpty(), 422, 'No feedback found for this project.');

        abort_unless(
            $feedback->first()->tenant_id === $request->user()->tenant_id,
            404
        );

        $result = $this->analysis->summarize($feedback, $request->user()->tenant_id);

        return response()->json([
            'project_id'  => $projectId,
            'feedback_count' => $feedback->count(),
            'summary'     => $result['summary'],
            'usage'       => [
                'tokens_used' => $result['tokens_used'],
                'cost_usd'    => $result['cost_usd'],
            ],
        ]);
    }
}
