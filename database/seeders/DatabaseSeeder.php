<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(['email' => 'admin@alignlaundry.test'], [
            'name' => 'Amina Nansubuga',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $worker = User::updateOrCreate(['email' => 'worker@alignlaundry.test'], [
            'name' => 'Joel Kato',
            'password' => Hash::make('password'),
            'role' => 'worker',
            'is_active' => true,
        ]);

        $samples = [
            ['name' => 'Maya Atwine', 'phone' => '+256772341908', 'email' => 'maya@example.test', 'status' => 'ready', 'shoe' => 'Running shoes', 'service' => 'Deep clean', 'price' => 30000],
            ['name' => 'Daniel Ssemanda', 'phone' => '+256704825116', 'email' => 'daniel@example.test', 'status' => 'washing', 'shoe' => 'Leather sneakers', 'service' => 'Premium care', 'price' => 45000],
            ['name' => 'Lillian Akello', 'phone' => '+256781552094', 'email' => null, 'status' => 'received', 'shoe' => 'Canvas shoes', 'service' => 'Standard wash', 'price' => 20000],
            ['name' => 'Isaac Mugisha', 'phone' => '+256755613420', 'email' => 'isaac@example.test', 'status' => 'collected', 'shoe' => 'Basketball shoes', 'service' => 'Deep clean', 'price' => 35000],
        ];

        foreach ($samples as $index => $sample) {
            $client = Client::updateOrCreate(['phone' => $sample['phone']], [
                'name' => $sample['name'],
                'email' => $sample['email'],
            ]);

            $order = Order::firstOrCreate(['reference' => 'ASL-DEMO-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)], [
                'client_id' => $client->id,
                'created_by' => $index % 2 === 0 ? $worker->id : $admin->id,
                'status' => $sample['status'],
                'received_at' => now()->subDays(3 - $index),
                'due_at' => now()->addDays($index),
                'ready_at' => $sample['status'] === 'ready' ? now() : null,
                'collected_at' => $sample['status'] === 'collected' ? now()->subDay() : null,
                'subtotal' => $sample['price'],
                'discount' => 0,
                'total' => $sample['price'],
            ]);

            if ($order->wasRecentlyCreated) {
                $order->items()->create([
                    'shoe_type' => $sample['shoe'],
                    'brand' => ['Nike', 'Adidas', 'Converse', 'Puma'][$index],
                    'color' => ['White', 'Black', 'Cream', 'Blue'][$index],
                    'service' => $sample['service'],
                    'quantity' => 1,
                    'unit_price' => $sample['price'],
                ]);

                $order->statusHistory()->create([
                    'from_status' => null,
                    'to_status' => $sample['status'],
                    'changed_by' => $worker->id,
                    'notes' => 'Demo order status.',
                ]);

                if (in_array($sample['status'], ['ready', 'collected'], true)) {
                    $order->payments()->create([
                        'amount' => $sample['price'],
                        'method' => 'mobile_money',
                        'reference' => 'DEMO-'.$index,
                        'paid_at' => now()->subDays(2 - $index),
                        'recorded_by' => $worker->id,
                    ]);
                }
            }
        }
    }
}
