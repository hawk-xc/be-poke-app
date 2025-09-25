<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // Visitor Permissions
            'visitor:list',
            'visitor:edit',
            'visitor:create',
            'visitor:delete',
            'visitor:export',

            // Role Permissions
            'roles:list',
            'roles:edit',
            'roles:create',
            'roles:delete',
            'roles:export',

            // Permission Permissions
            'permissions:list',
            'permissions:edit',
            'permissions:create',
            'permissions:delete',
            'permissions:export',

            // User Permissions
            'users:list',
            'users:edit',
            'users:create',
            'users:delete',
            'users:export',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $admin = Role::firstOrCreate(['name' => 'admin']);

        $admin->syncPermissions(Permission::all());


    }
}
