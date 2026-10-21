<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'admin')
            ->where('email', 'admin@alignsneakerlaundry')
            ->update([
                'email' => 'admin@gofreshshoelaundry',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', 'admin')
            ->where('email', 'admin@gofreshshoelaundry')
            ->update([
                'email' => 'admin@alignsneakerlaundry',
                'updated_at' => now(),
            ]);
    }
};
