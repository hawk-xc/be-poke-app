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
            'username' => 't130',
            'firstname' => 'T',
            'lastname' => '130 codename SSA',
            'name' => 'T130SSA',
            'email' => 't130@skynet.id',
            'password' => bcrypt('rootme'),
            'secure_password' =>  encrypt('rootme')
        ]);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $adminRole->syncPermissions(Permission::all());

        $user->assignRole($adminRole);
    }
}
