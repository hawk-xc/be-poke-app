<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Visitor Permissions
        Permission::create(['name' => 'visitor:list']);
        Permission::create(['name' => 'visitor:edit']);
        Permission::create(['name' => 'visitor:create']);
        Permission::create(['name' => 'visitor:delete']);
        Permission::create(['name' => 'visitor:export']);
        
        // Role Permissions
        Permission::create(['name' => 'roles:list']);
        Permission::create(['name' => 'roles:edit']);
        Permission::create(['name' => 'roles:create']);
        Permission::create(['name' => 'roles:delete']);
        Permission::create(['name' => 'roles:export']);

        // Permission Permissions
        Permission::create(['name' => 'permissions:list']);
        Permission::create(['name' => 'permissions:edit']);
        Permission::create(['name' => 'permissions:create']);
        Permission::create(['name' => 'permissions:delete']);
        Permission::create(['name' => 'permissions:export']);

        // User Permissions
        Permission::create(['name' => 'users:list']);
        Permission::create(['name' => 'users:edit']);
        Permission::create(['name' => 'users:create']);
        Permission::create(['name' => 'users:delete']);
        Permission::create(['name' => 'users:export']);
    }
}
