<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();

        $divisionA = Division::factory()->create(['tenant_id' => $this->tenantA->id]);
        $divisionB = Division::factory()->create(['tenant_id' => $this->tenantB->id]);

        $this->userA = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->userB = User::factory()->create(['tenant_id' => $this->tenantB->id]);

        $projectA = Project::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'division_id' => $divisionA->id,
        ]);

        $projectB = Project::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'division_id' => $divisionB->id,
        ]);

        Feedback::withoutEvents(function () use ($projectA, $projectB) {
            Feedback::factory()->count(3)->create([
                'tenant_id' => $this->tenantA->id,
                'project_id' => $projectA->id,
                'user_id' => $this->userA->id,
            ]);

            Feedback::factory()->count(2)->create([
                'tenant_id' => $this->tenantB->id,
                'project_id' => $projectB->id,
                'user_id' => $this->userB->id,
            ]);
        });
    }

    public function test_user_only_sees_their_tenant_divisions(): void
    {
        $this->actingAs($this->userA);

        $divisions = Division::all();

        $this->assertCount(1, $divisions);
        $this->assertTrue($divisions->every(fn ($d) => $d->tenant_id === $this->tenantA->id));
    }

    public function test_user_only_sees_their_tenant_feedback(): void
    {
        $this->actingAs($this->userA);

        $feedback = Feedback::all();

        $this->assertCount(3, $feedback);
        $this->assertTrue($feedback->every(fn ($f) => $f->tenant_id === $this->tenantA->id));
    }

    public function test_user_only_sees_their_tenant_projects(): void
    {
        $this->actingAs($this->userA);
        $projectsA = Project::all();

        $this->actingAs($this->userB);
        $projectsB = Project::all();

        $this->assertCount(1, $projectsA);
        $this->assertCount(1, $projectsB);

        $this->assertTrue($projectsA->every(fn ($p) => $p->tenant_id === $this->tenantA->id));
        $this->assertTrue($projectsB->every(fn ($p) => $p->tenant_id === $this->tenantB->id));
    }

    public function test_tenant_b_user_cannot_see_tenant_a_feedback(): void
    {
        $this->actingAs($this->userB);

        $feedback = Feedback::all();

        $this->assertCount(2, $feedback);
        $this->assertTrue($feedback->every(fn ($f) => $f->tenant_id === $this->tenantB->id));
        $this->assertTrue($feedback->every(fn ($f) => $f->tenant_id !== $this->tenantA->id));
    }

    public function test_global_scope_is_bypassed_with_without_global_scopes(): void
    {
        $this->actingAs($this->userA);

        $allFeedback = Feedback::withoutGlobalScopes()->get();
        $scopedFeedback = Feedback::all();

        $this->assertGreaterThan($scopedFeedback->count(), $allFeedback->count());
        $this->assertEquals(5, $allFeedback->count());
    }
}
