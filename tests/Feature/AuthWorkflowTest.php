<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AuthWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_stateful_api_uses_sanctum_session_middleware_only_once(): void
    {
        $route = app('router')->getRoutes()->match(Request::create(
            '/api/cleaning-sessions/1/status',
            'PATCH',
        ));

        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertNotContains('web', $route->gatherMiddleware());
        $this->assertTrue((bool) config('session.expire_on_close'));
        $this->assertGreaterThanOrEqual(525600, config('session.lifetime'));
    }

    public function test_active_staff_member_can_login_use_the_session_and_logout(): void
    {
        $user = User::factory()->create([
            'name' => 'Align Administrator',
            'email' => 'admin@alignlaundry.test',
            'password' => 'password',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->postJson('/api/login', [
            'email' => 'admin@alignlaundry.test',
            'password' => 'password',
            'remember' => true,
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'admin');

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@alignlaundry.test');

        $this->postJson('/api/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_inactive_staff_member_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'inactive@alignlaundry.test',
            'password' => 'password',
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'inactive@alignlaundry.test',
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_configured_owner_credentials_can_login(): void
    {
        $this->seed();

        $this->postJson('/api/login', [
            'email' => 'admin@gofreshshoelaundry',
            'password' => 'admin1234',
        ])->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.email', 'admin@gofreshshoelaundry');
    }
}
