<?php

namespace Database\Seeders;

use App\Models\Carrera;
use App\Models\Panol;
use App\Models\Role;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Roles
        $this->call(RoleSeeder::class);

        $rolAdmin = Role::where('nombre', Role::ADMIN)->firstOrFail();
        $rolDirector = Role::where('nombre', Role::DIRECTOR)->firstOrFail();
        $rolPanol = Role::where('nombre', Role::PANOL)->firstOrFail();
        $rolAyudante = Role::where('nombre', Role::AYUDANTE_PANOL)->firstOrFail();
        $rolDocente = Role::where('nombre', Role::DOCENTE)->firstOrFail();

        // 2. Pañoles
        $panolCentral = Panol::firstOrCreate(
            ['codigo' => 'PAN-01'],
            [
                'nombre' => 'Pañol Central',
                'ubicacion_general' => 'Edificio Central - Piso 1',
            ]
        );

        $panolElectronica = Panol::firstOrCreate(
            ['codigo' => 'PAN-02'],
            [
                'nombre' => 'Pañol Electrónica y Telecomunicaciones',
                'ubicacion_general' => 'Edificio Talleres - Piso 2',
            ]
        );

        // 3. Carreras (según check del DDL: ISD, II, IC, AP)
        $carreras = [
            ['codigo' => 'ISD', 'nombre' => 'Ingeniería en Ciberseguridad'],
            ['codigo' => 'II',  'nombre' => 'Ingeniería en Informática'],
            ['codigo' => 'IC',  'nombre' => 'Ingeniería en Conectividad y Redes'],
            ['codigo' => 'AP',  'nombre' => 'Analista Programador'],
        ];

        $carrerasModels = [];
        foreach ($carreras as $carData) {
            $carrerasModels[$carData['codigo']] = Carrera::firstOrCreate(
                ['codigo' => $carData['codigo']],
                ['nombre' => $carData['nombre']]
            );
        }

        // 4. Usuarios por cada Rol
        $passwordComun = Hash::make('Sgia2026!');

        // 4.1 Administrador (sin pañol asignado)
        Usuario::firstOrCreate(
            ['email' => 'admin@sgia.cl'],
            [
                'rol_id' => $rolAdmin->id,
                'panol_id' => null,
                'nombre' => 'Administrador General',
                'password' => $passwordComun,
                'activo' => true,
            ]
        );

        // 4.2 Director de Carrera (asociado a sus carreras vía pivote)
        $director = Usuario::firstOrCreate(
            ['email' => 'director@sgia.cl'],
            [
                'rol_id' => $rolDirector->id,
                'panol_id' => null,
                'nombre' => 'Director de Carrera',
                'password' => $passwordComun,
                'activo' => true,
            ]
        );
        $director->carreras()->syncWithoutDetaching([
            $carrerasModels['ISD']->id,
            $carrerasModels['II']->id,
            $carrerasModels['IC']->id,
            $carrerasModels['AP']->id,
        ]);

        // 4.3 Pañolero Encargado (asignado a Pañol Central)
        Usuario::firstOrCreate(
            ['email' => 'panol@sgia.cl'],
            [
                'rol_id' => $rolPanol->id,
                'panol_id' => $panolCentral->id,
                'nombre' => 'Encargado de Pañol',
                'password' => $passwordComun,
                'activo' => true,
            ]
        );

        // 4.4 Ayudante de Pañol (asignado a Pañol Central)
        Usuario::firstOrCreate(
            ['email' => 'ayudante.panol@sgia.cl'],
            [
                'rol_id' => $rolAyudante->id,
                'panol_id' => $panolCentral->id,
                'nombre' => 'Ayudante de Pañol',
                'password' => $passwordComun,
                'activo' => true,
            ]
        );

        // 4.5 Docente (asociado a carreras)
        $docente = Usuario::firstOrCreate(
            ['email' => 'docente@sgia.cl'],
            [
                'rol_id' => $rolDocente->id,
                'panol_id' => null,
                'nombre' => 'Docente Especialidad',
                'password' => $passwordComun,
                'activo' => true,
            ]
        );
        $docente->carreras()->syncWithoutDetaching([
            $carrerasModels['ISD']->id,
            $carrerasModels['II']->id,
        ]);
    }
}
