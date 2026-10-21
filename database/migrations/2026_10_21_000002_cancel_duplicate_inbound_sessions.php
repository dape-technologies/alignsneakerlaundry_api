<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $activeStatuses = ['received', 'cleaning', 'ready_for_collection'];
        $affectedOrderIds = [];

        $sessionsByShoe = DB::table('cleaning_sessions')
            ->whereIn('status', $activeStatuses)
            ->orderBy('shoe_id')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get()
            ->groupBy('shoe_id');

        foreach ($sessionsByShoe as $sessions) {
            foreach ($sessions->slice(1) as $duplicate) {
                $order = DB::table('orders')->find($duplicate->order_id);
                DB::table('cleaning_sessions')->where('id', $duplicate->id)->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);
                DB::table('cleaning_session_status_history')->insert([
                    'cleaning_session_id' => $duplicate->id,
                    'from_status' => $duplicate->status,
                    'to_status' => 'cancelled',
                    'changed_by' => $order->created_by,
                    'notes' => 'Automatically cancelled while removing duplicate inbound footwear records.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $affectedOrderIds[] = $duplicate->order_id;
            }
        }

        foreach (array_unique($affectedOrderIds) as $orderId) {
            $statuses = DB::table('cleaning_sessions')->where('order_id', $orderId)->pluck('status');
            $active = $statuses->reject(fn ($status) => $status === 'cancelled');
            $status = $active->isEmpty()
                ? 'cancelled'
                : collect(['received', 'cleaning', 'ready_for_collection', 'collected'])
                    ->first(fn ($candidate) => $active->contains($candidate), 'collected');
            DB::table('orders')->where('id', $orderId)->update(['status' => $status, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Duplicate records cannot be safely restored as simultaneous inbound footwear.
    }
};
