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

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Division $division;
    private Project $project;
    private User $admin;
    private User $manager;
    private User $member;
    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->division = Division::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->project  = Project::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'division_id' => $this->division->id,
        ]);

        $this->admin   = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->member  = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->support = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->division->users()->attach([
            $this->admin->id   => ['role' => 'admin'],
            $this->manager->id => ['role' => 'manager'],
            $this->member->id  => ['role' => 'member'],
            $this->support->id => ['role' => 'support'],
        ]);

        Queue::fake();
    }

    // --- Listing ---

    public function test_index_returns_paginated_feedback(): void
    {
        Feedback::factory()->count(5)->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id'    => $this->member->id,
        ]);

        $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/feedback')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'title', 'status', 'project_id']],
                'links',
                'meta',
            ]);
    }

    public function test_index_filters_by_status(): void
    {
        Feedback::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id'    => $this->member->id,
            'status'     => 'open',
        ]);
        Feedback::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id'    => $this->member->id,
            'status'     => 'resolved',
        ]);

        $response = $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/feedback?status=open')
            ->assertOk();

        $statuses = collect($response->json('data'))->pluck('status')->unique()->values();
        $this->assertEquals(['open'], $statuses->toArray());
    }

    public function test_index_does_not_return_other_tenant_feedback(): void
    {
        $otherTenant = Tenant::factory()->create();
        Feedback::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/feedback')
            ->assertOk();

        $tenantIds = collect($response->json('data'))->pluck('tenant_id')->unique()->values();
        $this->assertNotContains($otherTenant->id, $tenantIds->toArray());
    }

    // --- Show ---

    public function test_show_returns_feedback_for_own_tenant(): void
    {
        $feedback = Feedback::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id'    => $this->member->id,
        ]);

        $this->actingAs($this->member, 'sanctum')
            ->getJson("/api/feedback/{$feedback->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $feedback->id);
    }

    public function test_show_returns_404_for_other_tenant_feedback(): void
    {
        $otherTenant   = Tenant::factory()->create();
        $otherFeedback = Feedback::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($this->member, 'sanctum')
            ->getJson("/api/feedback/{$otherFeedback->id}")
            ->assertNotFound();
    }

    // --- Store ---

    public function test_member_can_create_feedback(): void
    {
        $response = $this->actingAs($this->member, 'sanctum')
            ->postJson('/api/feedback', [
                'project_id'  => $this->project->id,
                'title'       => 'Login button not responding',
                'description' => 'Happens on iOS 17.',
                'status'      => 'open',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Login Button Not Responding')
            ->assertJsonPath('data.tenant_id', $this->tenant->id);
    }

    public function test_support_role_cannot_create_feedback(): void
    {
        $this->actingAs($this->support, 'sanctum')
            ->postJson('/api/feedback', [
                'project_id' => $this->project->id,
                'title'      => 'A feedback title',
            ])->assertForbidden();
    }

    public function test_user_not_in_division_cannot_create_feedback(): void
    {
        $outsider = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/feedback', [
                'project_id' => $this->project->id,
                'title'      => 'A feedback title',
            ])->assertForbidden();
    }

    public function test_store_requires_title_and_project_id(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->postJson('/api/feedback', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'project_id']);
    }

    // --- Update Status ---

    public function test_manager_can_update_feedback_status(): void
    {
        $feedback = Feedback::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id'    => $this->member->id,
            'status'     => 'open',
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/feedback/{$feedback->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_admin_can_update_feedback_status(): void
    {
        $feedback = Feedback::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id'    => $this->member->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/feedback/{$feedback->id}/status", ['status' => 'resolved'])
            ->assertOk();
    }

    public function test_member_cannot_update_feedback_status(): void
    {
        $feedback = Feedback::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id'    => $this->member->id,
        ]);

        $this->actingAs($this->member, 'sanctum')
            ->patchJson("/api/feedback/{$feedback->id}/status", ['status' => 'resolved'])
            ->assertForbidden();
    }

    public function test_support_cannot_update_feedback_status(): void
    {
        $feedback = Feedback::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id'    => $this->member->id,
        ]);

        $this->actingAs($this->support, 'sanctum')
            ->patchJson("/api/feedback/{$feedback->id}/status", ['status' => 'resolved'])
            ->assertForbidden();
    }

    public function test_update_status_rejects_invalid_status(): void
    {
        $feedback = Feedback::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id'    => $this->member->id,
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/feedback/{$feedback->id}/status", ['status' => 'not-a-real-status'])
            ->assertUnprocessable();
    }

    // --- Delete ---

    public function test_admin_can_delete_feedback(): void
    {
        $feedback = Feedback::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id'    => $this->member->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/feedback/{$feedback->id}")
            ->assertOk()
            ->assertJson(['message' => 'Feedback deleted.']);

        $this->assertSoftDeleted('feedback', ['id' => $feedback->id]);
    }

    public function test_manager_cannot_delete_feedback(): void
    {
        $feedback = Feedback::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id'    => $this->member->id,
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/feedback/{$feedback->id}")
            ->assertForbidden();
    }

    public function test_member_cannot_delete_feedback(): void
    {
        $feedback = Feedback::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id'    => $this->member->id,
        ]);

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson("/api/feedback/{$feedback->id}")
            ->assertForbidden();
    }

    public function test_cannot_delete_other_tenant_feedback(): void
    {
        $otherTenant   = Tenant::factory()->create();
        $otherFeedback = Feedback::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/feedback/{$otherFeedback->id}")
            ->assertNotFound();
    }
}
