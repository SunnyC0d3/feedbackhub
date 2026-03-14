<?php

namespace Tests\Integration;

use App\Jobs\StoreFeedbackEmbedding;
use App\Models\Division;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AIService;
use App\Services\EmbeddingService;
use App\Services\FeedbackAnalysisService;
use App\Services\PineconeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class PineconeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Project $project;

    private array $fakeVector;

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

        $this->fakeVector = array_fill(0, 1536, 0.01);
    }

    public function test_analyze_by_query_returns_summary_when_matches_found(): void
    {
        $feedback = Feedback::withoutEvents(fn () => Feedback::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]));

        $feedbackIds = $feedback->pluck('id')->toArray();

        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('generateEmbedding')
            ->once()
            ->with('login issues on mobile')
            ->andReturn($this->fakeVector);

        $mockPinecone = Mockery::mock(PineconeService::class);
        $mockPinecone->shouldReceive('query')
            ->once()
            ->with($this->fakeVector, 10, ['tenant_id' => $this->tenant->id])
            ->andReturn([
                'matches' => [
                    ['id' => "feedback-{$feedbackIds[0]}", 'score' => 0.92, 'metadata' => ['feedback_id' => $feedbackIds[0]]],
                    ['id' => "feedback-{$feedbackIds[1]}", 'score' => 0.87, 'metadata' => ['feedback_id' => $feedbackIds[1]]],
                ],
            ]);

        $mockAi = Mockery::mock(AIService::class);
        $mockAi->shouldReceive('summarizeFeedback')
            ->once()
            ->andReturn([
                'summary' => 'Users report login failures on iOS devices.',
                'tokens_used' => 410,
                'cost_usd' => 0.000062,
                'feedback_count' => 2,
            ]);

        $this->app->instance(EmbeddingService::class, $mockEmbedding);
        $this->app->instance(PineconeService::class, $mockPinecone);
        $this->app->instance(AIService::class, $mockAi);

        $service = app(FeedbackAnalysisService::class);
        $result = $service->analyzeByQuery('login issues on mobile', $this->tenant->id);

        $this->assertEquals('login issues on mobile', $result['query']);
        $this->assertEquals(2, $result['feedback_found']);
        $this->assertCount(2, $result['matches']);
        $this->assertStringContainsString('login failures', $result['summary']);
        $this->assertEquals(410, $result['tokens_used']);
        $this->assertEquals(0.000062, $result['cost_usd']);
    }

    public function test_analyze_by_query_returns_empty_result_when_no_pinecone_matches(): void
    {
        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('generateEmbedding')
            ->once()
            ->andReturn($this->fakeVector);

        $mockPinecone = Mockery::mock(PineconeService::class);
        $mockPinecone->shouldReceive('query')
            ->once()
            ->andReturn(['matches' => []]);

        $mockAi = Mockery::mock(AIService::class);
        $mockAi->shouldNotReceive('summarizeFeedback');

        $this->app->instance(EmbeddingService::class, $mockEmbedding);
        $this->app->instance(PineconeService::class, $mockPinecone);
        $this->app->instance(AIService::class, $mockAi);

        $service = app(FeedbackAnalysisService::class);
        $result = $service->analyzeByQuery('something obscure', $this->tenant->id);

        $this->assertEquals(0, $result['feedback_found']);
        $this->assertEmpty($result['matches']);
        $this->assertNull($result['summary']);
    }

    public function test_analyze_by_query_returns_empty_when_pinecone_ids_not_in_db(): void
    {
        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('generateEmbedding')->once()->andReturn($this->fakeVector);

        $mockPinecone = Mockery::mock(PineconeService::class);
        $mockPinecone->shouldReceive('query')->once()->andReturn([
            'matches' => [
                ['id' => 'feedback-99999', 'score' => 0.9, 'metadata' => ['feedback_id' => 99999]],
            ],
        ]);

        $mockAi = Mockery::mock(AIService::class);
        $mockAi->shouldNotReceive('summarizeFeedback');

        $this->app->instance(EmbeddingService::class, $mockEmbedding);
        $this->app->instance(PineconeService::class, $mockPinecone);
        $this->app->instance(AIService::class, $mockAi);

        $service = app(FeedbackAnalysisService::class);
        $result = $service->analyzeByQuery('ghost feedback', $this->tenant->id);

        $this->assertEquals(0, $result['feedback_found']);
        $this->assertNull($result['summary']);
    }

    public function test_analyze_by_query_passes_tenant_filter_to_pinecone(): void
    {
        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('generateEmbedding')->once()->andReturn($this->fakeVector);

        $mockPinecone = Mockery::mock(PineconeService::class);
        $mockPinecone->shouldReceive('query')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::any(),
                Mockery::on(fn ($filter) => ($filter['tenant_id'] ?? null) === $this->tenant->id)
            )
            ->andReturn(['matches' => []]);

        $this->app->instance(EmbeddingService::class, $mockEmbedding);
        $this->app->instance(PineconeService::class, $mockPinecone);

        $service = app(FeedbackAnalysisService::class);
        $service->analyzeByQuery('any query', $this->tenant->id);
    }

    public function test_store_feedback_embedding_job_is_dispatched_on_feedback_creation(): void
    {
        Bus::fake();

        Feedback::factory()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]);

        Bus::assertDispatched(StoreFeedbackEmbedding::class);
    }

    public function test_store_feedback_embedding_job_calls_embedding_and_pinecone_services(): void
    {
        $feedback = Feedback::withoutEvents(fn () => Feedback::factory()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]));

        $expectedText = $feedback->title . ' ' . $feedback->description;

        $mockEmbedding = Mockery::mock(EmbeddingService::class);
        $mockEmbedding->shouldReceive('generateEmbedding')
            ->once()
            ->with($expectedText)
            ->andReturn($this->fakeVector);

        $mockPinecone = Mockery::mock(PineconeService::class);
        $mockPinecone->shouldReceive('upsert')
            ->once()
            ->with(Mockery::on(function ($vectors) use ($feedback) {
                return $vectors[0]['id'] === "feedback-{$feedback->id}"
                    && $vectors[0]['metadata']['feedback_id'] === $feedback->id
                    && $vectors[0]['metadata']['tenant_id'] === $feedback->tenant_id;
            }))
            ->andReturn(['upsertedCount' => 1]);

        $this->app->instance(EmbeddingService::class, $mockEmbedding);
        $this->app->instance(PineconeService::class, $mockPinecone);

        $job = new StoreFeedbackEmbedding($feedback->id);
        $job->handle();
    }
}
