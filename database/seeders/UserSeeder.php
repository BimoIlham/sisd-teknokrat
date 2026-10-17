<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Administrator LPM
        $admin = User::firstOrCreate(
            ['email' => 'lpm@teknokrat.ac.id'],
            [
                'name' => 'Administrator LPM',
                'password' => Hash::make('Password123!'),
                'is_active' => true,
                'must_change_password' => false,
            ]
        );
        if (! $admin->hasRole('superadmin')) {
            $admin->assignRole('lpm_admin');
        }
    }
}
