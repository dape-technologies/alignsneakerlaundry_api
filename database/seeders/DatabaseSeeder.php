<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('INITIAL_ADMIN_EMAIL');
        $password = env('INITIAL_ADMIN_PASSWORD');

        if (app()->isProduction() && (! $email || ! $password)) {
            throw new \RuntimeException('Production admin credentials are not configured.');
        }

        $admin = User::firstOrCreate(['role' => 'admin'], [
            'name' => env('INITIAL_ADMIN_NAME', 'Go Fresh Owner'),
            'email' => $email ?: 'admin@gofreshshoelaundry',
            'password' => Hash::make($password ?: 'admin1234'),
            'is_active' => true,
        ]);

        if (! $admin->wasRecentlyCreated) {
            if ($email && in_array($admin->email, [
                'admin@alignsneakerlaundry',
                'admin@gofreshshoelaundry',
            ], true)) {
                $admin->email = $email;
            }

            if ($password && Hash::check('admin1234', $admin->password)) {
                $admin->password = Hash::make($password);
            }

            $admin->save();
        }
    }
}
