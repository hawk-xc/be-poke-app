<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('users')->delete();

        $user = User::create([
            'username' => 'admintwb12',
            'firstname' => 'Admin',
            'lastname' => 'true',
            'name' => 'Admin TWB',
            'email' => 'admin@deraly.id',
            'password' => bcrypt('rootme'),
        ]);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $adminRole->syncPermissions(Permission::all());

        $user->assignRole($adminRole);
    }
}
