<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(['role' => 'admin'], [
            'name' => 'Go Fresh Owner',
            'email' => 'admin@gofreshshoelaundry',
            'password' => Hash::make('admin1234'),
            'is_active' => true,
        ]);
    }
}
