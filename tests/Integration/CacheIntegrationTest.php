<?php

namespace Tests\Integration;

use App\Models\Division;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CacheService;
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

    public function test_cache_stores_and_retrieves_value(): void
    {
        $key = CacheService::key('test', $this->tenant->id, 'value');
        $data = ['foo' => 'bar', 'count' => 42];

        CacheService::put($key, $data, 60);

        $retrieved = CacheService::get($key);

        $this->assertEquals($data, $retrieved);
    }

    public function test_cache_miss_returns_null(): void
    {
        $key = CacheService::key('nonexistent', $this->tenant->id, 'key');

        $this->assertNull(CacheService::get($key));
    }

    public function test_cache_remember_stores_and_returns_computed_value(): void
    {
        $key = CacheService::key('test_remember', $this->tenant->id);
        $callCount = 0;

        $result1 = CacheService::remember($key, 60, function () use (&$callCount) {
            $callCount++;
            return ['computed' => true];
        });

        $result2 = CacheService::remember($key, 60, function () use (&$callCount) {
            $callCount++;
            return ['computed' => true];
        });

        $this->assertEquals(['computed' => true], $result1);
        $this->assertEquals($result1, $result2);
        $this->assertEquals(1, $callCount, 'Callback should only run once on cache hit');
    }

    public function test_cache_direct_forget_removes_value(): void
    {
        // CacheService::forget() uses Redis KEYS pattern matching — not compatible
        // with the array driver used in tests. Test the underlying Cache::forget() instead.
        $key = CacheService::key('test_forget', $this->tenant->id);

        CacheService::put($key, 'some-value', 60);
        $this->assertEquals('some-value', CacheService::get($key));

        Cache::forget($key);
        $this->assertNull(CacheService::get($key));
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
