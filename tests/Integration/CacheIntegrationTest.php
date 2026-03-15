<?php

namespace Tests\Integration;

use App\Models\Division;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CacheIntegrationTest extends TestCase
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
    }

    public function test_metrics_cache_is_invalidated_when_feedback_created(): void
    {
        MetricsService::getDashboardMetrics($this->tenant->id);

        $metricsCacheKey = "metrics:dashboard:{$this->tenant->id}";
        $this->assertNotNull(Cache::get($metricsCacheKey));

        Feedback::factory()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]);

        $this->assertNull(Cache::get($metricsCacheKey));
    }

    public function test_metrics_cache_is_invalidated_when_feedback_status_updated(): void
    {
        $feedback = Feedback::withoutEvents(fn () => Feedback::factory()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'status' => 'draft',
        ]));

        MetricsService::getDashboardMetrics($this->tenant->id);
        $metricsCacheKey = "metrics:dashboard:{$this->tenant->id}";
        $this->assertNotNull(Cache::get($metricsCacheKey));

        $feedback->update(['status' => 'closed']);

        $this->assertNull(Cache::get($metricsCacheKey));
    }
}
