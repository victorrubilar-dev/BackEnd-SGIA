<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            'Administrador',
            'Director de carrera',
            'Pañol',
            'Ayudante de pañol',
            'Docente',
        ];

        foreach ($roles as $nombre) {
            Role::firstOrCreate(['nombre' => $nombre]);
        }
    }
}
