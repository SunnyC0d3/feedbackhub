<?php

namespace Tests\Performance;

use App\Models\Division;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\FeedbackRepository;
use App\Repositories\ProjectRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueryPerformanceTest extends TestCase
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
            Feedback::factory()->count(20)->create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'user_id' => $this->user->id,
            ]);
        });
    }

    public function test_feedback_listing_by_project_uses_index(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $repo = app(FeedbackRepository::class);
        $repo->findByProject($this->project->id);

        $queries = DB::getQueryLog();
        $this->assertNotEmpty($queries);

        $sql = $queries[0]['query'] ?? $queries[0]['sql'];
        $bindings = $queries[0]['bindings'];

        $explain = DB::select("EXPLAIN $sql", $bindings);

        $usesIndex = collect($explain)->some(fn ($row) => !empty($row->key));
        $this->assertTrue($usesIndex, 'findByProject should use an index (project_id_created_at or similar)');
    }

    public function test_feedback_listing_by_status_uses_index(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $repo = app(FeedbackRepository::class);
        $repo->findByStatus('draft', $this->tenant->id);

        $queries = DB::getQueryLog();
        $this->assertNotEmpty($queries);

        $sql = $queries[0]['query'] ?? $queries[0]['sql'];
        $bindings = $queries[0]['bindings'];

        $explain = DB::select("EXPLAIN $sql", $bindings);

        $usesIndex = collect($explain)->some(fn ($row) => !empty($row->key));
        $this->assertTrue($usesIndex, 'findByStatus should use an index (project_id_status or similar)');
    }

    public function test_project_with_metrics_does_not_cause_n_plus_one(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $repo = app(ProjectRepository::class);
        $repo->findWithMetrics($this->project->id);

        $queries = DB::getQueryLog();

        $this->assertLessThanOrEqual(3, count($queries),
            'findWithMetrics caused N+1 — got ' . count($queries) . ' queries'
        );
    }

    public function test_feedback_repository_find_all_for_tenant_does_not_cause_n_plus_one(): void
    {
        $division2 = Division::factory()->create(['tenant_id' => $this->tenant->id]);
        $project2 = Project::factory()->create([
            'tenant_id' => $this->tenant->id,
            'division_id' => $division2->id,
        ]);

        Feedback::withoutEvents(fn () => Feedback::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project2->id,
            'user_id' => $this->user->id,
        ]));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $repo = app(FeedbackRepository::class);
        $repo->findRecentForTenant($this->tenant->id, 20);

        $queries = DB::getQueryLog();

        $this->assertLessThanOrEqual(2, count($queries),
            'findRecentForTenant caused too many queries — got ' . count($queries)
        );
    }

    public function test_pending_feedback_query_uses_tenant_scope(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $repo = app(FeedbackRepository::class);
        $pending = $repo->findPendingForTenant($this->tenant->id);

        $queries = DB::getQueryLog();
        $sql = $queries[0]['query'] ?? $queries[0]['sql'];

        $this->assertStringContainsString('tenant_id', $sql);
        $this->assertInstanceOf(Collection::class, $pending);
    }
}
