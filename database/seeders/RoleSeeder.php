<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public const ROLES = [
        'AD-01' => 'Administrador',
        'DIR-01' => 'Director de Carrera',
        'PAN-01' => 'Pañolero',
        'PRO-01' => 'Docente',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $code => $name) {
            Role::updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
