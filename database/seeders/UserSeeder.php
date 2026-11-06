<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Administrator',
            'username' => 'admin',
            'full_name' => 'Administrator Bank Mini',
            'email' => 'admin@bankmini.test',
            'password' => Hash::make('password'),
            'role' => 'administrator',
            'status' => 'active',
        ]);
    }
}
