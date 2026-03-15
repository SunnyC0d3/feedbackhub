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

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Division $division;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->division = Division::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user     = User::factory()->create(['tenant_id' => $this->tenant->id]);

        Queue::fake();
    }

    public function test_index_returns_paginated_projects(): void
    {
        Project::factory()->count(3)->create([
            'tenant_id'   => $this->tenant->id,
            'division_id' => $this->division->id,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/projects')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug', 'division_id', 'feedback_count']],
                'links',
                'meta',
            ]);
    }

    public function test_index_only_returns_own_tenant_projects(): void
    {
        $ownProject   = Project::factory()->create(['tenant_id' => $this->tenant->id, 'division_id' => $this->division->id]);
        $otherProject = Project::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/projects')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($ownProject->id, $ids);
        $this->assertNotContains($otherProject->id, $ids);
    }

    public function test_show_returns_project_with_metrics(): void
    {
        $project = Project::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'division_id' => $this->division->id,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonStructure(['data' => ['id', 'name', 'feedback_count', 'division']]);
    }

    public function test_show_returns_404_for_other_tenant_project(): void
    {
        $otherProject = Project::factory()->create();

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/projects/{$otherProject->id}")
            ->assertNotFound();
    }

    public function test_project_feedback_returns_paginated_results(): void
    {
        $project = Project::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'division_id' => $this->division->id,
        ]);

        Feedback::factory()->count(3)->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $project->id,
            'user_id'    => $this->user->id,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/projects/{$project->id}/feedback")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'title', 'status']],
                'links',
                'meta',
            ]);
    }

    public function test_project_feedback_does_not_leak_other_tenant_feedback(): void
    {
        $otherProject  = Project::factory()->create();
        $otherFeedback = Feedback::factory()->create(['tenant_id' => $otherProject->tenant_id, 'project_id' => $otherProject->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/projects/{$otherProject->id}/feedback")
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($otherFeedback->id, $ids);
    }
}
