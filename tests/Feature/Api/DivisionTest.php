<?php

namespace Tests\Feature\Api;

use App\Models\Division;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DivisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user   = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_index_returns_own_tenant_divisions(): void
    {
        $ownDivision   = Division::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherDivision = Division::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/divisions')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'tenant_id']]]);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($ownDivision->id, $ids);
        $this->assertNotContains($otherDivision->id, $ids);
    }

    public function test_show_returns_division_with_projects_and_user_count(): void
    {
        $division = Division::factory()->create(['tenant_id' => $this->tenant->id]);
        Project::factory()->create(['tenant_id' => $this->tenant->id, 'division_id' => $division->id]);
        $division->users()->attach($this->user->id, ['role' => 'member']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/divisions/{$division->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $division->id)
            ->assertJsonStructure(['data' => ['id', 'name', 'user_count', 'projects']]);
    }

    public function test_show_returns_404_for_other_tenant_division(): void
    {
        $otherDivision = Division::factory()->create();

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/divisions/{$otherDivision->id}")
            ->assertNotFound();
    }
}
