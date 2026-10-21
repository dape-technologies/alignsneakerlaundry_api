<?php

namespace Tests\Feature;

use App\Jobs\SendPickupReadyEmail;
use App\Mail\PickupReadyMail;
use App\Models\CleaningSession;
use App\Models\Client;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\Shoe;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LaundryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_creates_a_single_shoe_order_without_overwriting_client(): void
    {
        $worker = User::factory()->create(['role' => 'worker']);
        $client = Client::create(['name' => 'Grace Namusoke', 'phone' => '+256772555010', 'email' => 'grace@example.test']);
        $existing = Shoe::create(['client_id' => $client->id, 'reference' => 'ASL-S-000001', 'shoe_type' => 'Sneakers', 'brand' => 'Nike']);
        Sanctum::actingAs($worker);

        $response = $this->postJson('/api/orders', [
            'client_id' => $client->id,
            'sessions' => [
                ['shoe_id' => $existing->id, 'tag_id' => Tag::first()->id, 'service' => 'Deep clean', 'unit_price' => 30000],
            ],
            'deposit' => 10000,
            'payment_method' => 'mobile_money',
        ]);

        $response->assertCreated()
            ->assertJsonPath('client_name', 'Grace Namusoke')
            ->assertJsonCount(1, 'cleaning_sessions')
            ->assertJsonPath('cleaning_sessions.0.tag.label', 'Tag 01')
            ->assertJsonPath('total', 30000)
            ->assertJsonPath('balance', 20000);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('shoes', 1);
        $this->assertDatabaseCount('cleaning_sessions', 1);
        $this->assertSame('grace@example.test', $client->fresh()->email);
    }

    public function test_each_return_visit_creates_a_new_session_for_the_same_shoe(): void
    {
        $worker = User::factory()->create(['role' => 'worker']);
        $client = Client::create(['name' => 'Peter Okello', 'phone' => '+256701234567', 'email' => 'peter@example.test']);
        $shoe = Shoe::create(['client_id' => $client->id, 'reference' => 'ASL-S-000002', 'shoe_type' => 'Running shoes']);
        Sanctum::actingAs($worker);

        $first = $this->postJson('/api/orders', [
            'client_id' => $client->id,
            'sessions' => [['shoe_id' => $shoe->id, 'tag_id' => Tag::first()->id, 'service' => 'Deep clean', 'unit_price' => 25000]],
        ])->assertCreated();
        $this->patchJson('/api/cleaning-sessions/'.$first->json('cleaning_sessions.0.id').'/status', [
            'status' => 'cancelled', 'expected_status' => 'received', 'notes' => 'Client changed their mind.',
        ])->assertOk();
        $this->postJson('/api/orders', [
            'client_id' => $client->id,
            'sessions' => [['shoe_id' => $shoe->id, 'tag_id' => Tag::first()->id, 'service' => 'Deep clean', 'unit_price' => 30000]],
        ])->assertCreated();

        $this->assertDatabaseCount('shoes', 1);
        $this->assertDatabaseCount('cleaning_sessions', 2);
    }

    public function test_registering_a_client_also_registers_their_required_footwear(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'worker', 'is_active' => true]));

        $this->postJson('/api/clients', [
            'name' => 'Doreen Akello',
            'phone' => '0772555011',
            'email' => 'doreen@example.test',
            'shoe' => ['shoe_type' => 'Sneakers', 'brand' => 'Adidas', 'model' => 'Samba'],
        ])->assertCreated()
            ->assertJsonPath('shoes.0.shoe_type', 'Sneakers')
            ->assertJsonPath('shoes.0.brand', 'Adidas');

        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('shoes', 1);
        $this->assertDatabaseHas('shoes', ['model' => 'Samba']);
    }

    public function test_client_registration_requires_footwear(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'worker', 'is_active' => true]));

        $this->postJson('/api/clients', [
            'name' => 'No Shoe Client',
            'phone' => '0772555012',
            'email' => 'noshoe@example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('shoe.shoe_type');
    }

    public function test_owner_can_delete_an_order_and_its_roadmap_card(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [$session, $order] = $this->sessionAt('received', $owner);
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/orders/{$order->id}")->assertNoContent();

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('cleaning_sessions', ['id' => $session->id]);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonCount(0, 'active_sessions');
    }

    public function test_attendant_cannot_delete_an_order(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [, $order] = $this->sessionAt('received', $owner);
        Sanctum::actingAs(User::factory()->create(['role' => 'worker', 'is_active' => true]));

        $this->deleteJson("/api/orders/{$order->id}")->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
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

    public function test_worker_can_move_footwear_through_allowed_active_roadmap_stages(): void
    {
        Queue::fake();
        $worker = User::factory()->create(['role' => 'worker']);
        [$session] = $this->sessionAt('received', $worker);
        Sanctum::actingAs($worker);

        $this->patchJson("/api/cleaning-sessions/{$session->id}/status", [
            'status' => 'cleaning',
            'expected_status' => 'received',
        ])->assertOk();
        $this->patchJson("/api/cleaning-sessions/{$session->id}/status", [
            'status' => 'ready_for_collection',
            'expected_status' => 'cleaning',
        ])->assertOk();

        $this->assertDatabaseHas('cleaning_session_status_history', [
            'cleaning_session_id' => $session->id, 'from_status' => 'received', 'to_status' => 'cleaning',
        ]);
        $this->assertDatabaseHas('cleaning_session_status_history', [
            'cleaning_session_id' => $session->id, 'from_status' => 'cleaning', 'to_status' => 'ready_for_collection',
        ]);
        $this->assertNotNull($session->fresh()->ready_at);
    }

    public function test_worker_login_session_remains_authenticated_during_a_roadmap_move(): void
    {
        $worker = User::factory()->create([
            'email' => 'roadmap.worker@example.test',
            'password' => 'password',
            'role' => 'worker',
            'is_active' => true,
        ]);
        [$session] = $this->sessionAt('received', $worker);
        $this->withHeader('Origin', 'http://localhost:5173');

        $this->postJson('/api/login', [
            'email' => 'roadmap.worker@example.test',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.role', 'worker');

        $this->patchJson("/api/cleaning-sessions/{$session->id}/status", [
            'status' => 'cleaning',
            'expected_status' => 'received',
        ])->assertOk()->assertJsonPath('status', 'cleaning');

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $worker->id);
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

    public function test_order_requires_one_shoe_and_an_available_tag(): void
    {
        $worker = User::factory()->create(['role' => 'worker']);
        $client = Client::create(['name' => 'Tag Tester', 'phone' => '+256700000111', 'email' => 'tags@example.test']);
        $firstShoe = Shoe::create(['client_id' => $client->id, 'reference' => 'ASL-S-TAG-1', 'shoe_type' => 'Sneakers']);
        $secondShoe = Shoe::create(['client_id' => $client->id, 'reference' => 'ASL-S-TAG-2', 'shoe_type' => 'Boots']);
        Sanctum::actingAs($worker);

        $this->postJson('/api/orders', [
            'client_id' => $client->id,
            'sessions' => [
                ['shoe_id' => $firstShoe->id, 'tag_id' => Tag::find(1)->id, 'service' => 'Deep clean', 'unit_price' => 30000],
                ['shoe_id' => $secondShoe->id, 'tag_id' => Tag::find(2)->id, 'service' => 'Standard wash', 'unit_price' => 20000],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('sessions');

        $order = $this->postJson('/api/orders', [
            'client_id' => $client->id,
            'sessions' => [['shoe_id' => $firstShoe->id, 'tag_id' => Tag::find(1)->id, 'service' => 'Deep clean', 'unit_price' => 30000]],
        ])->assertCreated();

        $this->getJson('/api/tags/available')->assertOk()->assertJsonCount(19, 'data');
        $this->postJson('/api/orders', [
            'client_id' => $client->id,
            'sessions' => [['shoe_id' => $firstShoe->id, 'tag_id' => Tag::find(2)->id, 'service' => 'Deep clean', 'unit_price' => 30000]],
        ])->assertUnprocessable()->assertJsonValidationErrors('sessions.0.shoe_id');

        $this->patchJson('/api/cleaning-sessions/'.$order->json('cleaning_sessions.0.id').'/status', [
            'status' => 'cancelled', 'expected_status' => 'received', 'notes' => 'Duplicate intake corrected.',
        ])->assertOk();
        $this->getJson('/api/tags/available')->assertOk()->assertJsonCount(20, 'data');
    }

    public function test_cancellation_requires_a_reason_and_is_visible_to_the_owner(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        [$session, $order] = $this->sessionAt('received', $owner);
        Sanctum::actingAs($owner);

        $this->patchJson("/api/cleaning-sessions/{$session->id}/status", [
            'status' => 'cancelled', 'expected_status' => 'received',
        ])->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->patchJson("/api/cleaning-sessions/{$session->id}/status", [
            'status' => 'cancelled', 'expected_status' => 'received', 'notes' => 'Customer requested cancellation.',
        ])->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('cancelled_orders.0.id', $order->id)
            ->assertJsonPath('cancelled_orders.0.cleaning_sessions.0.status_history.0.notes', 'Customer requested cancellation.');
    }

    public function test_collection_requires_full_payment_and_collected_orders_remain_listed(): void
    {
        $worker = User::factory()->create(['role' => 'worker']);
        [$session, $order] = $this->sessionAt('ready_for_collection', $worker);
        Sanctum::actingAs($worker);

        $this->patchJson("/api/cleaning-sessions/{$session->id}/status", [
            'status' => 'collected', 'expected_status' => 'ready_for_collection',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/orders/{$order->id}/payments", [
            'amount' => 26000, 'method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("/api/orders/{$order->id}/payments", [
            'amount' => 25000, 'method' => 'cash',
        ])->assertCreated();
        $this->patchJson("/api/cleaning-sessions/{$session->id}/status", [
            'status' => 'collected', 'expected_status' => 'ready_for_collection',
        ])->assertOk();

        $this->assertSame('collected', $order->fresh()->status);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonCount(0, 'active_sessions');
        $this->getJson('/api/orders?scope=collected')->assertOk()->assertJsonPath('data.0.id', $order->id);
    }

    public function test_cancelled_order_payments_are_retained_but_excluded_from_income(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        [$session, $order] = $this->sessionAt('received', $owner);
        Sanctum::actingAs($owner);

        $this->postJson("/api/orders/{$order->id}/payments", ['amount' => 10000, 'method' => 'cash'])->assertCreated();
        $this->patchJson("/api/cleaning-sessions/{$session->id}/status", [
            'status' => 'cancelled', 'expected_status' => 'received', 'notes' => 'Cancelled after deposit.',
        ])->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'amount' => 10000]);
        $this->getJson('/api/reports')->assertOk()->assertJsonPath('collected_revenue', 0);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('metrics.month_revenue', 0);
        $this->postJson("/api/orders/{$order->id}/payments", ['amount' => 1000, 'method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_client_directory_filters_by_client_shoe_and_service_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $amina = Client::create(['name' => 'Amina Kintu', 'phone' => '+256700000101', 'email' => 'amina@example.test']);
        $brian = Client::create(['name' => 'Brian Ouma', 'phone' => '+256700000102', 'email' => 'brian@example.test']);
        $aminaShoe = $amina->shoes()->create(['reference' => 'ASL-S-101', 'shoe_type' => 'Running shoes', 'brand' => 'Nike', 'model' => 'Air Max']);
        $brian->shoes()->create(['reference' => 'ASL-S-102', 'shoe_type' => 'Loafers', 'brand' => 'Clarks', 'model' => 'Torhill']);
        $aminaOrder = $amina->orders()->create([
            'reference' => 'ASL-FILTER-01', 'client_name' => $amina->name,
            'notification_phone' => $amina->phone, 'notification_email' => $amina->email,
            'created_by' => $admin->id, 'status' => 'received', 'received_at' => '2026-10-12 10:00:00',
            'subtotal' => 30000, 'total' => 30000,
        ]);
        CleaningSession::create([
            'order_id' => $aminaOrder->id, 'shoe_id' => $aminaShoe->id, 'tag_id' => Tag::first()->id,
            'status' => 'received', 'service' => 'Deep clean', 'unit_price' => 30000,
            'received_at' => '2026-10-12 10:00:00',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/clients?client_name=Amina')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Amina Kintu')
            ->assertJsonPath('data.0.shoes.0.cleaning_sessions_count', 1)
            ->assertJsonPath('data.0.shoes.0.last_service_at', '2026-10-12 10:00:00');
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
            'order_id' => $order->id, 'shoe_id' => $shoe->id, 'tag_id' => Tag::first()->id, 'status' => $status,
            'service' => 'Deep clean', 'unit_price' => 25000, 'received_at' => now(),
            'ready_cycle' => in_array($status, ['ready_for_collection', 'collected'], true) ? 1 : 0,
        ]);

        return [$session, $order];
    }
}
