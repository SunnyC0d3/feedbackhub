<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AIService;
use App\Services\FeedbackAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AiSummarizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $division = Division::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->project = Project::factory()->create([
            'tenant_id' => $this->tenant->id,
            'division_id' => $division->id,
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);

        Feedback::withoutEvents(function () {
            Feedback::factory()->count(3)->create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'user_id' => $this->user->id,
            ]);
        });
    }

    public function test_summarize_calls_ai_service_and_returns_summary(): void
    {
        $feedback = Feedback::all();

        $mockedAi = Mockery::mock(AIService::class);
        $mockedAi->shouldReceive('summarizeFeedback')
            ->once()
            ->with(Mockery::on(fn ($items) => count($items) === 3), $this->tenant->id)
            ->andReturn([
                'summary' => 'Users are experiencing login issues on mobile devices.',
                'tokens_used' => 320,
                'cost_usd' => 0.000048,
                'feedback_count' => 3,
            ]);

        $this->app->instance(AIService::class, $mockedAi);

        $service = app(FeedbackAnalysisService::class);
        $result = $service->summarize($feedback, $this->tenant->id);

        $this->assertEquals(3, $result['feedback_found']);
        $this->assertStringContainsString('login issues', $result['summary']);
        $this->assertEquals(320, $result['tokens_used']);
        $this->assertEquals(0.000048, $result['cost_usd']);
    }

    public function test_summarize_returns_empty_result_for_empty_collection(): void
    {
        $service = app(FeedbackAnalysisService::class);
        $result = $service->summarize(collect(), $this->tenant->id);

        $this->assertEquals(0, $result['feedback_found']);
        $this->assertNull($result['summary']);
        $this->assertNull($result['tokens_used']);
        $this->assertNull($result['cost_usd']);
    }

    public function test_ai_service_usage_is_tracked_after_summarization(): void
    {
        $service = app(AIService::class);

        $feedback = Feedback::all();

        $mockedAi = Mockery::mock(AIService::class);
        $trackedUsage = null;

        $mockedAi->shouldReceive('summarizeFeedback')
            ->once()
            ->andReturnUsing(function () use (&$trackedUsage) {
                $trackedUsage = [
                    'summary' => 'Mock summary.',
                    'tokens_used' => 200,
                    'cost_usd' => 0.00003,
                    'feedback_count' => 3,
                ];
                return $trackedUsage;
            });

        $this->app->instance(AIService::class, $mockedAi);

        $analysisService = app(FeedbackAnalysisService::class);
        $result = $analysisService->summarize($feedback, $this->tenant->id);

        $this->assertNotNull($result['tokens_used']);
        $this->assertNotNull($result['cost_usd']);
        $this->assertGreaterThan(0, $result['tokens_used']);
    }
}
