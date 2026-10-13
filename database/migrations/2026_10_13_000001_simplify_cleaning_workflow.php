<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cleaning_sessions')
            ->whereIn('status', ['drying', 'quality_check'])
            ->update(['status' => 'cleaning']);

        DB::table('orders')
            ->whereIn('status', ['drying', 'quality_check'])
            ->update(['status' => 'cleaning']);
    }

    public function down(): void
    {
        // Historical drying and quality-check values cannot be reconstructed reliably.
    }
};
