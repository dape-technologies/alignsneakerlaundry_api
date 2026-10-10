<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('client_name')->nullable()->after('client_id');
            $table->string('notification_phone')->nullable()->after('client_name');
            $table->string('notification_email')->nullable()->after('notification_phone');
        });

        Schema::create('shoes', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable()->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('shoe_type');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->text('identifying_notes')->nullable();
            $table->string('reference_photo_path')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['client_id', 'active']);
        });

        Schema::create('cleaning_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shoe_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('received')->index();
            $table->string('service');
            $table->text('condition_notes')->nullable();
            $table->unsignedBigInteger('unit_price');
            $table->unsignedInteger('ready_cycle')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('cleaning_at')->nullable();
            $table->timestamp('drying_at')->nullable();
            $table->timestamp('quality_checked_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        Schema::create('cleaning_session_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaning_session_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaning_session_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key')->unique();
            $table->string('event')->default('ready_for_collection');
            $table->string('channel')->default('mail');
            $table->string('trigger')->default('automatic');
            $table->string('destination');
            $table->string('status')->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });

        Schema::table('order_photos', function (Blueprint $table) {
            $table->foreignId('cleaning_session_id')->nullable()->after('order_item_id')->constrained()->nullOnDelete();
        });

        $statusMap = ['washing' => 'cleaning', 'ready' => 'ready_for_collection'];

        DB::table('orders')->orderBy('id')->each(function ($order) use ($statusMap) {
            $client = DB::table('clients')->find($order->client_id);
            $mappedStatus = $statusMap[$order->status] ?? $order->status;

            DB::table('orders')->where('id', $order->id)->update([
                'client_name' => $client?->name,
                'notification_phone' => $client?->phone,
                'notification_email' => $client?->email,
                'status' => $mappedStatus,
            ]);

            $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get();
            foreach ($items as $item) {
                $firstSessionId = null;
                foreach (range(1, max(1, (int) $item->quantity)) as $copy) {
                    $shoeId = DB::table('shoes')->insertGetId([
                        'client_id' => $order->client_id,
                        'shoe_type' => $item->shoe_type,
                        'brand' => $item->brand,
                        'color' => $item->color,
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ]);
                    DB::table('shoes')->where('id', $shoeId)->update([
                        'reference' => 'ASL-S-'.str_pad((string) $shoeId, 6, '0', STR_PAD_LEFT),
                    ]);

                    $sessionId = DB::table('cleaning_sessions')->insertGetId([
                        'order_id' => $order->id,
                        'shoe_id' => $shoeId,
                        'status' => $mappedStatus,
                        'service' => $item->service,
                        'condition_notes' => $item->condition_notes,
                        'unit_price' => $item->unit_price,
                        'ready_cycle' => in_array($mappedStatus, ['ready_for_collection', 'collected'], true) ? 1 : 0,
                        'received_at' => $order->received_at,
                        'ready_at' => $order->ready_at,
                        'collected_at' => $order->collected_at,
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ]);

                    DB::table('cleaning_session_status_history')->insert([
                        'cleaning_session_id' => $sessionId,
                        'from_status' => null,
                        'to_status' => $mappedStatus,
                        'changed_by' => $order->created_by,
                        'notes' => 'Migrated from the original service order.',
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ]);

                    $firstSessionId ??= $sessionId;
                }

                DB::table('order_photos')->where('order_item_id', $item->id)->update([
                    'cleaning_session_id' => $firstSessionId,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cleaning_session_id');
        });
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('cleaning_session_status_history');
        Schema::dropIfExists('cleaning_sessions');
        Schema::dropIfExists('shoes');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['client_name', 'notification_phone', 'notification_email']);
        });
    }
};
