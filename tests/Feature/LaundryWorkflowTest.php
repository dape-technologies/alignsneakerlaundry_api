<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderReadyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LaundryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_can_create_a_complete_laundry_order(): void
    {
        $worker = User::factory()->create(['role' => 'worker']);
        Sanctum::actingAs($worker);

        $response = $this->postJson('/api/orders', [
            'client' => [
                'name' => 'Grace Namusoke',
                'phone' => '0772 555 010',
                'email' => 'grace@example.test',
            ],
            'items' => [[
                'shoe_type' => 'Running shoes',
                'brand' => 'Nike',
                'color' => 'White',
                'service' => 'Deep clean',
                'quantity' => 1,
                'unit_price' => 30000,
            ]],
            'deposit' => 10000,
            'payment_method' => 'mobile_money',
        ]);

        $response->assertCreated()
            ->assertJsonPath('client.phone', '+256772555010')
            ->assertJsonPath('total', 30000)
            ->assertJsonPath('amount_paid', 10000)
            ->assertJsonPath('balance', 20000);

        $this->assertDatabaseHas('orders', ['status' => 'received', 'total' => 30000]);
        $this->assertDatabaseHas('order_items', ['shoe_type' => 'Running shoes']);
    }

    public function test_ready_status_sends_the_client_notification_once(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create([
            'name' => 'Peter Okello',
            'phone' => '+256701234567',
            'email' => 'peter@example.test',
        ]);
        $order = Order::create([
            'reference' => 'ASL-TEST-001',
            'client_id' => $client->id,
            'created_by' => $admin->id,
            'status' => 'quality_check',
            'received_at' => now(),
            'subtotal' => 25000,
            'total' => 25000,
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'ready'])
            ->assertOk()
            ->assertJsonPath('status', 'ready');

        Notification::assertSentToTimes($client, OrderReadyNotification::class, 1);
        $this->assertNotNull($order->fresh()->ready_notified_at);
    }

    public function test_only_administrators_can_view_financial_reports(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'worker']));
        $this->getJson('/api/reports')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/reports')->assertOk()->assertJsonStructure([
            'orders', 'clients', 'quoted_revenue', 'collected_revenue', 'outstanding',
        ]);
    }
}
