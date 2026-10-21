<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('name', 'Align Owner')->update([
            'name' => 'Go Fresh Owner',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('users')->where('name', 'Go Fresh Owner')->update([
            'name' => 'Align Owner',
            'updated_at' => now(),
        ]);
    }
};
