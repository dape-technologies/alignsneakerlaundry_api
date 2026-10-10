<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(['email' => 'admin@alignlaundry.test'], [
            'name' => 'Amina Nansubuga', 'password' => Hash::make('password'), 'role' => 'admin', 'is_active' => true,
        ]);
        $worker = User::updateOrCreate(['email' => 'worker@alignlaundry.test'], [
            'name' => 'Joel Kato', 'password' => Hash::make('password'), 'role' => 'worker', 'is_active' => true,
        ]);

        $samples = [
            ['Maya Atwine', '+256772341908', 'maya@example.test', 'ready_for_collection', 'Running shoes', 'Nike', 'Vomero 17', 'White', 'Deep clean', 30000],
            ['Daniel Ssemanda', '+256704825116', 'daniel@example.test', 'cleaning', 'Leather sneakers', 'Adidas', 'Stan Smith', 'Black', 'Premium care', 45000],
            ['Lillian Akello', '+256781552094', 'lillian@example.test', 'received', 'Canvas shoes', 'Converse', 'Chuck 70', 'Cream', 'Standard wash', 20000],
            ['Isaac Mugisha', '+256755613420', 'isaac@example.test', 'collected', 'Basketball shoes', 'Puma', 'All Pro Nitro', 'Blue', 'Deep clean', 35000],
            ['Sarah Nakiwunga', '+256701384522', 'sarah@example.test', 'drying', 'Training shoes', 'New Balance', 'Fresh Foam X', 'Grey', 'Deep clean', 32000],
            ['Michael Ocen', '+256787443091', 'michael@example.test', 'quality_check', 'Suede sneakers', 'Clarks', 'Torhill', 'Tan', 'Premium care', 50000],
            ['Grace Namusoke', '+256759220184', 'grace@example.test', 'cancelled', 'Kids sneakers', 'Vans', 'Old Skool', 'Pink', 'Standard wash', 18000],
            ['Ronald Kato', '+256743865219', 'ronald@example.test', 'ready_for_collection', 'Football boots', 'Nike', 'Mercurial', 'Red', 'Premium care', 55000],
            ['Maya Atwine', '+256772341908', 'maya@example.test', 'collected', 'Running shoes', 'Nike', 'Vomero 17', 'White', 'Re-clean', 15000],
        ];

        foreach ($samples as $index => [$name, $phone, $email, $status, $type, $brand, $model, $color, $service, $price]) {
            $client = Client::updateOrCreate(['phone' => $phone], compact('name', 'email'));
            $shoe = $client->shoes()->firstOrCreate(['brand' => $brand, 'model' => $model, 'color' => $color], [
                'shoe_type' => $type,
                'size' => (string) (40 + $index),
            ]);
            if (! $shoe->reference) {
                $shoe->update(['reference' => 'ASL-S-'.str_pad((string) $shoe->id, 6, '0', STR_PAD_LEFT)]);
            }

            $order = Order::firstOrCreate(['reference' => 'ASL-DEMO-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)], [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'notification_phone' => $client->phone,
                'notification_email' => $client->email,
                'created_by' => $index % 2 === 0 ? $worker->id : $admin->id,
                'status' => $status,
                'received_at' => now()->subDays(3 - $index),
                'due_at' => now()->addDays($index),
                'ready_at' => $status === 'ready_for_collection' ? now() : null,
                'collected_at' => $status === 'collected' ? now()->subDay() : null,
                'subtotal' => $price,
                'total' => $price,
            ]);

            if ($order->cleaningSessions()->doesntExist()) {
                $session = $order->cleaningSessions()->create([
                    'shoe_id' => $shoe->id,
                    'status' => $status,
                    'service' => $service,
                    'unit_price' => $price,
                    'ready_cycle' => in_array($status, ['ready_for_collection', 'collected'], true) ? 1 : 0,
                    'received_at' => $order->received_at,
                    'cleaning_at' => in_array($status, ['cleaning', 'drying', 'quality_check', 'ready_for_collection', 'collected'], true) ? $order->received_at->copy()->addHours(3) : null,
                    'drying_at' => in_array($status, ['drying', 'quality_check', 'ready_for_collection', 'collected'], true) ? $order->received_at->copy()->addHours(8) : null,
                    'quality_checked_at' => in_array($status, ['quality_check', 'ready_for_collection', 'collected'], true) ? $order->received_at->copy()->addDay() : null,
                    'ready_at' => $order->ready_at,
                    'collected_at' => $order->collected_at,
                ]);
                $session->statusHistory()->create([
                    'from_status' => null, 'to_status' => $status, 'changed_by' => $worker->id, 'notes' => 'Demo care status.',
                ]);

                if (in_array($status, ['ready_for_collection', 'collected'], true)) {
                    $session->notifications()->firstOrCreate(
                        ['idempotency_key' => "demo-ready:{$session->id}:1"],
                        [
                            'event' => 'ready_for_collection',
                            'channel' => 'mail',
                            'trigger' => 'automatic',
                            'destination' => $client->email,
                            'status' => 'sent',
                            'attempts' => 1,
                            'queued_at' => $order->ready_at ?? now()->subHours(2),
                            'sent_at' => $order->ready_at ?? now()->subHours(2),
                        ],
                    );
                }
            }

            $amountPaid = match ($status) {
                'collected' => $price,
                'ready_for_collection' => intdiv($price, 2),
                'cleaning', 'drying', 'quality_check' => min(10000, $price),
                default => 0,
            };
            if ($amountPaid > 0 && $order->payments()->doesntExist()) {
                $order->payments()->create([
                    'amount' => $amountPaid, 'method' => 'mobile_money', 'reference' => 'DEMO-'.$index,
                    'paid_at' => now()->subDays(max(0, 2 - $index)), 'recorded_by' => $worker->id,
                ]);
            }
        }
    }
}
