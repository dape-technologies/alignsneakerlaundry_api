<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(['email' => 'admin@alignlaundry.test'], [
            'name' => 'Amina Nansubuga', 'password' => Hash::make('password'), 'role' => 'admin', 'is_active' => true,
        ]);
        User::updateOrCreate(['email' => 'worker@alignlaundry.test'], [
            'name' => 'Joel Kato', 'password' => Hash::make('password'), 'role' => 'worker', 'is_active' => true,
        ]);
    }
}
