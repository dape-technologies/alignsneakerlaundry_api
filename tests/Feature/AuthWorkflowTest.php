<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthWorkflowTest extends TestCase
{
    use RefreshDatabase;

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
}
