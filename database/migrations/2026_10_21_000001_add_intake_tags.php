<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $activeSessionIds = DB::table('cleaning_sessions')
            ->whereIn('status', ['received', 'cleaning', 'ready_for_collection'])
            ->orderBy('id')
            ->pluck('id');

        if ($activeSessionIds->count() > 20) {
            throw new RuntimeException('There are more than 20 inbound shoes. Resolve them before enabling the 20-tag inventory.');
        }

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('number')->unique();
            $table->timestamps();
        });

        foreach (range(1, 20) as $number) {
            DB::table('tags')->insert([
                'number' => $number,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('cleaning_sessions', function (Blueprint $table) {
            $table->foreignId('tag_id')->nullable()->after('shoe_id')->constrained()->restrictOnDelete();
        });

        $tagIds = DB::table('tags')->orderBy('number')->pluck('id');
        foreach ($activeSessionIds as $index => $sessionId) {
            DB::table('cleaning_sessions')->where('id', $sessionId)->update(['tag_id' => $tagIds[$index]]);
        }
    }

    public function down(): void
    {
        Schema::table('cleaning_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tag_id');
        });
        Schema::dropIfExists('tags');
    }
};
