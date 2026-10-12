<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Superadmin LPM
        $admin = User::firstOrCreate(
            ['email' => 'lpm@teknokrat.ac.id'],
            [
                'name' => 'Administrator LPM',
                'password' => Hash::make('Password123!'),
                'is_active' => true,
                'must_change_password' => false,
            ]
        );
        $admin->assignRole('superadmin');
    }
}
