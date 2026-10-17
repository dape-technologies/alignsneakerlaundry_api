<?php

namespace Tests\Feature;

use App\Jobs\SendPickupReadyEmail;
use App\Mail\PickupReadyMail;
use App\Models\CleaningSession;
use App\Models\Client;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\Shoe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LaundryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_creates_an_order_for_existing_and_new_footwear_without_overwriting_client(): void
    {
        $worker = User::factory()->create(['role' => 'worker']);
        $client = Client::create(['name' => 'Grace Namusoke', 'phone' => '+256772555010', 'email' => 'grace@example.test']);
        $existing = Shoe::create(['client_id' => $client->id, 'reference' => 'ASL-S-000001', 'shoe_type' => 'Sneakers', 'brand' => 'Nike']);
        Sanctum::actingAs($worker);

        $response = $this->postJson('/api/orders', [
            'client_id' => $client->id,
            'sessions' => [
                ['shoe_id' => $existing->id, 'service' => 'Deep clean', 'unit_price' => 30000],
                ['shoe' => ['shoe_type' => 'Loafers', 'brand' => 'Clarks', 'color' => 'Brown'], 'service' => 'Premium care', 'unit_price' => 45000],
            ],
            'deposit' => 10000,
            'payment_method' => 'mobile_money',
        ]);

        $response->assertCreated()
            ->assertJsonPath('client_name', 'Grace Namusoke')
            ->assertJsonCount(2, 'cleaning_sessions')
            ->assertJsonPath('total', 75000)
            ->assertJsonPath('balance', 65000);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('shoes', 2);
        $this->assertDatabaseCount('cleaning_sessions', 2);
        $this->assertSame('grace@example.test', $client->fresh()->email);
    }

    public function test_each_return_visit_creates_a_new_session_for_the_same_shoe(): void
    {
        $worker = User::factory()->create(['role' => 'worker']);
        $client = Client::create(['name' => 'Peter Okello', 'phone' => '+256701234567', 'email' => 'peter@example.test']);
        $shoe = Shoe::create(['client_id' => $client->id, 'reference' => 'ASL-S-000002', 'shoe_type' => 'Running shoes']);
        Sanctum::actingAs($worker);

        foreach ([25000, 30000] as $price) {
            $this->postJson('/api/orders', [
                'client_id' => $client->id,
                'sessions' => [['shoe_id' => $shoe->id, 'service' => 'Deep clean', 'unit_price' => $price]],
            ])->assertCreated();
        }

        $this->assertDatabaseCount('shoes', 1);
        $this->assertDatabaseCount('cleaning_sessions', 2);
    }

    public function test_ready_status_queues_one_email_per_readiness_cycle(): void
    {
        Queue::fake();
        $worker = User::factory()->create(['role' => 'worker']);
        [$session] = $this->sessionAt('cleaning', $worker);
        Sanctum::actingAs($worker);

        $this->patchJson("/api/cleaning-sessions/{$session->id}/status", ['status' => 'ready_for_collection'])
            ->assertOk()
            ->assertJsonPath('status', 'ready_for_collection');

        Queue::assertPushed(SendPickupReadyEmail::class, 1);
        $this->assertDatabaseHas('notification_deliveries', [
            'cleaning_session_id' => $session->id, 'status' => 'queued', 'trigger' => 'automatic',
        ]);
        $this->assertSame(1, $session->fresh()->ready_cycle);
    }

    public function test_worker_can_move_footwear_between_any_active_roadmap_stages(): void
    {
        Queue::fake();
        $worker = User::factory()->create(['role' => 'worker']);
        [$session] = $this->sessionAt('received', $worker);
        Sanctum::actingAs($worker);

        $this->patchJson("/api/cleaning-sessions/{$session->id}/status", [
            'status' => 'ready_for_collection',
            'expected_status' => 'received',
        ])->assertOk();
        $this->patchJson("/api/cleaning-sessions/{$session->id}/status", [
            'status' => 'received',
            'expected_status' => 'ready_for_collection',
        ])->assertOk();

        $this->assertDatabaseHas('cleaning_session_status_history', [
            'cleaning_session_id' => $session->id, 'from_status' => 'received', 'to_status' => 'ready_for_collection',
        ]);
        $this->assertDatabaseHas('cleaning_session_status_history', [
            'cleaning_session_id' => $session->id, 'from_status' => 'ready_for_collection', 'to_status' => 'received',
        ]);
        $this->assertNull($session->fresh()->ready_at);
    }

    public function test_ready_email_job_records_successful_delivery(): void
    {
        Mail::fake();
        $worker = User::factory()->create(['role' => 'worker']);
        [$session] = $this->sessionAt('ready_for_collection', $worker);
        $delivery = NotificationDelivery::create([
            'cleaning_session_id' => $session->id,
            'idempotency_key' => "ready:{$session->id}:1",
            'destination' => 'maya@example.test',
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        (new SendPickupReadyEmail($delivery))->handle();

        Mail::assertSent(PickupReadyMail::class, fn ($mail) => $mail->hasTo('maya@example.test'));
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_ready_transition_requires_an_email_destination(): void
    {
        $worker = User::factory()->create(['role' => 'worker']);
        [$session, $order] = $this->sessionAt('cleaning', $worker);
        $order->update(['notification_email' => null]);
        Sanctum::actingAs($worker);

        $this->patchJson("/api/cleaning-sessions/{$session->id}/status", ['status' => 'ready_for_collection'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_only_administrators_can_view_financial_reports(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'worker']));
        $this->getJson('/api/reports')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/reports')->assertOk();
    }

    public function test_stale_board_update_is_rejected(): void
    {
        $worker = User::factory()->create(['role' => 'worker']);
        [$session] = $this->sessionAt('received', $worker);
        $session->update(['status' => 'cleaning']);
        Sanctum::actingAs($worker);

        $this->patchJson("/api/cleaning-sessions/{$session->id}/status", [
            'status' => 'cleaning',
            'expected_status' => 'received',
        ])->assertConflict();
    }

    public function test_dashboard_returns_the_three_stage_pipeline_and_active_footwear(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$session] = $this->sessionAt('received', $admin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('pipeline_counts.received', 1)
            ->assertJsonPath('pipeline_counts.cleaning', 0)
            ->assertJsonPath('pipeline_counts.ready_for_collection', 0)
            ->assertJsonPath('active_sessions.0.id', $session->id)
            ->assertJsonPath('active_sessions.0.shoe.reference', 'ASL-S-000003');
    }

    public function test_client_directory_filters_by_client_shoe_and_service_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $amina = Client::create(['name' => 'Amina Kintu', 'phone' => '+256700000101', 'email' => 'amina@example.test']);
        $brian = Client::create(['name' => 'Brian Ouma', 'phone' => '+256700000102', 'email' => 'brian@example.test']);
        $amina->shoes()->create(['reference' => 'ASL-S-101', 'shoe_type' => 'Running shoes', 'brand' => 'Nike', 'model' => 'Air Max']);
        $brian->shoes()->create(['reference' => 'ASL-S-102', 'shoe_type' => 'Loafers', 'brand' => 'Clarks', 'model' => 'Torhill']);
        $amina->orders()->create([
            'reference' => 'ASL-FILTER-01', 'client_name' => $amina->name,
            'notification_phone' => $amina->phone, 'notification_email' => $amina->email,
            'created_by' => $admin->id, 'status' => 'received', 'received_at' => '2026-10-12 10:00:00',
            'subtotal' => 30000, 'total' => 30000,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/clients?client_name=Amina')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Amina Kintu');
        $this->getJson('/api/clients?shoe_name=Torhill')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Brian Ouma');
        $this->getJson('/api/clients?date=2026-10-12')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Amina Kintu');
    }

    private function sessionAt(string $status, User $worker): array
    {
        $client = Client::create(['name' => 'Maya Atwine', 'phone' => '+256772341908', 'email' => 'maya@example.test']);
        $shoe = Shoe::create(['client_id' => $client->id, 'reference' => 'ASL-S-000003', 'shoe_type' => 'Sneakers', 'brand' => 'Nike']);
        $order = Order::create([
            'reference' => 'ASL-TEST-001', 'client_id' => $client->id, 'client_name' => $client->name,
            'notification_phone' => $client->phone, 'notification_email' => $client->email,
            'created_by' => $worker->id, 'status' => $status, 'received_at' => now(),
            'subtotal' => 25000, 'total' => 25000,
        ]);
        $session = CleaningSession::create([
            'order_id' => $order->id, 'shoe_id' => $shoe->id, 'status' => $status,
            'service' => 'Deep clean', 'unit_price' => 25000, 'received_at' => now(),
            'ready_cycle' => in_array($status, ['ready_for_collection', 'collected'], true) ? 1 : 0,
        ]);

        return [$session, $order];
    }
}
