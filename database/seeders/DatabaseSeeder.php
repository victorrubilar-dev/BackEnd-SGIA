<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@sgia.cl'],
            [
                'name' => 'Administrador SGIA',
                'password' => Hash::make('Admin1234!'),
                'role' => User::ROLE_ADMIN,
                'area' => 'Informática',
                'is_active' => true,
            ]
        );
    }
}
