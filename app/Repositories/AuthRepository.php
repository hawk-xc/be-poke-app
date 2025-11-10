<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthRepository
{
    public function register(array $data): User
    {
        $userData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'username' => $data['username'],
            'firstname' => $data['firstname'],
            'lastname' => $data['lastname'] ?? null,
            'fullname' => $data['firstname'] . ' ' . ($data['lastname'] ?? ''),
        ];

        return User::create($userData);
    }
}
