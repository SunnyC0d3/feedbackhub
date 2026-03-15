<?php

namespace Tests\Feature\Api;

use App\Models\Division;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetricsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user   = User::factory()->create(['tenant_id' => $this->tenant->id]);

        Queue::fake();
    }

    public function test_metrics_returns_dashboard_data_for_tenant(): void
    {
        $division = Division::factory()->create(['tenant_id' => $this->tenant->id]);
        $project  = Project::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'division_id' => $division->id,
        ]);

        Feedback::factory()->count(3)->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $project->id,
            'user_id'    => $this->user->id,
            'status'     => 'open',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/metrics')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total_feedback',
                    'total_projects',
                    'total_users',
                    'feedback_by_status',
                    'feedback_today',
                    'feedback_this_week',
                    'failed_jobs',
                ],
            ]);
    }

    public function test_metrics_only_reflect_own_tenant_data(): void
    {
        $otherTenant = Tenant::factory()->create();
        Feedback::factory()->count(10)->create(['tenant_id' => $otherTenant->id]);

        Feedback::factory()->count(2)->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => Project::factory()->create([
                'tenant_id'   => $this->tenant->id,
                'division_id' => Division::factory()->create(['tenant_id' => $this->tenant->id])->id,
            ])->id,
            'user_id'    => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/metrics')
            ->assertOk();

        $this->assertEquals(2, $response->json('data.total_feedback'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/metrics')->assertUnauthorized();
    }
}
