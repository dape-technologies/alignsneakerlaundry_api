<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendantManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_register_list_and_revoke_an_attendant(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/attendants', [
            'name' => 'Sarah Attendant',
            'email' => 'sarah@align.test',
            'password' => 'securepass',
            'password_confirmation' => 'securepass',
        ])->assertCreated()
            ->assertJsonPath('data.role', 'worker')
            ->assertJsonPath('data.is_active', true);

        $attendantId = $response->json('data.id');
        $this->getJson('/api/attendants')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson("/api/attendants/{$attendantId}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('users', ['id' => $attendantId, 'role' => 'worker', 'is_active' => false]);
    }

    public function test_attendant_cannot_manage_other_attendants(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'worker', 'is_active' => true]));
        $this->getJson('/api/attendants')->assertForbidden();
        $this->postJson('/api/attendants', [])->assertForbidden();
    }

    public function test_revoked_attendant_is_rejected_on_authenticated_routes(): void
    {
        $attendant = User::factory()->create(['role' => 'worker', 'is_active' => false]);
        Sanctum::actingAs($attendant);

        $this->getJson('/api/dashboard')->assertForbidden();
    }
}
