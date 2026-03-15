<?php

namespace Tests\Feature\Api;

use App\Models\Division;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'test-corp']);
        $this->user   = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email'     => 'alice@test.com',
            'password'  => bcrypt('secret123'),
        ]);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'tenant_slug' => 'test-corp',
            'email'       => 'alice@test.com',
            'password'    => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'tenant_id']]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->postJson('/api/auth/login', [
            'tenant_slug' => 'test-corp',
            'email'       => 'alice@test.com',
            'password'    => 'wrongpassword',
        ])->assertUnauthorized();
    }

    public function test_login_fails_with_unknown_tenant_slug(): void
    {
        $this->postJson('/api/auth/login', [
            'tenant_slug' => 'does-not-exist',
            'email'       => 'alice@test.com',
            'password'    => 'secret123',
        ])->assertUnprocessable();
    }

    public function test_login_fails_when_email_does_not_exist_in_tenant(): void
    {
        $this->postJson('/api/auth/login', [
            'tenant_slug' => 'test-corp',
            'email'       => 'nobody@test.com',
            'password'    => 'secret123',
        ])->assertUnauthorized();
    }

    public function test_user_can_logout(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out.']);
    }

    public function test_logout_revokes_token(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // Token is deleted from the database
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id'   => $this->user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_me_returns_current_user(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJson([
                'id'        => $this->user->id,
                'email'     => 'alice@test.com',
                'tenant_id' => $this->tenant->id,
            ]);
    }

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/feedback')->assertUnauthorized();
        $this->getJson('/api/projects')->assertUnauthorized();
    }
}
