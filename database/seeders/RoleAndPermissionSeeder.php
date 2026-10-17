<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            'manage-academic',
            'import-schedules',
            'manage-supervisors',
            'manage-assignments',
            'manage-instruments',
            'view-analytics',
            'fill-supervisions',
            'view-personal-schedule',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // Create roles
        $superadmin = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $superadmin->syncPermissions(Permission::all());

        $lpmAdmin = Role::firstOrCreate(['name' => 'lpm_admin', 'guard_name' => 'web']);
        $lpmAdmin->syncPermissions(Permission::all());

        $supervisor = Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
        $supervisor->syncPermissions([
            'fill-supervisions',
            'view-personal-schedule',
        ]);

        Role::firstOrCreate(['name' => 'fakultas', 'guard_name' => 'web'])
            ->syncPermissions(['view-analytics']);
        Role::firstOrCreate(['name' => 'prodi', 'guard_name' => 'web'])
            ->syncPermissions(['view-analytics']);
    }
}
