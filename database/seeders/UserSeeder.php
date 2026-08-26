<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        User::upsert(
            [
                [
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                    'email_verified_at' => $now,
                    'password' => Hash::make('password'),
                    'remember_token' => Str::random(10),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            ['email'],
            ['name', 'email_verified_at', 'password', 'remember_token', 'updated_at'],
        );
    }
}
