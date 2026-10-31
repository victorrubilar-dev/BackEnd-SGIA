<?php

namespace Database\Seeders;

use App\Models\Cajon;
use App\Models\Location;
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
        // Catálogos base: también en producción y antes de crear usuarios.
        $this->call([
            RoleSeeder::class,
            StoreroomSeeder::class,
            CareerSeeder::class,
            ChartTemplateSeeder::class,
        ]);

        $password = config('sgia.seed_admin_password')
            ?? (app()->isProduction() ? null : 'Admin1234!');

        if ($password === null) {
            $this->command?->warn('Producción: define SEED_ADMIN_PASSWORD para crear el administrador inicial.');

            return;
        }

        $admin = User::updateOrCreate(
            ['email' => 'admin@sgia.cl'],
            [
                'name' => 'Administrador SGIA',
                'password' => Hash::make($password),
                'role' => User::ROLE_ADMIN,
                'area' => 'Informática',
                'is_active' => true,
            ]
        );

        // Datos de demostración: nunca en producción.
        if (app()->isProduction()) {
            return;
        }

        // Un usuario de prueba por rol (misma contraseña que el admin)
        foreach ([
            'director@sgia.cl' => ['Director de Carrera', User::ROLE_DIRECTOR],
            'panolero@sgia.cl' => ['Pañolero', User::ROLE_WAREHOUSE],
            'docente@sgia.cl' => ['Docente', User::ROLE_TEACHER],
        ] as $email => [$name, $role]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make($password),
                    'role' => $role,
                    'area' => 'Informática',
                    'is_active' => true,
                ]
            );
        }

        // Crear ubicaciones base (Salas y Pañoles)
        $panolCentral = Location::firstOrCreate(
            ['nombre' => 'Pañol Central'],
            [
                'tipo' => Location::TIPO_PANOL,
                'descripcion' => 'Pañol principal de herramientas y equipos de Electricidad y Telecomunicaciones',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );

        $labElectronica = Location::firstOrCreate(
            ['nombre' => 'Laboratorio E-301'],
            [
                'tipo' => Location::TIPO_SALA,
                'descripcion' => 'Laboratorio de Electrónica y Circuitos',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );

        $tallerElectricidad = Location::firstOrCreate(
            ['nombre' => 'Taller T-102'],
            [
                'tipo' => Location::TIPO_TALLER,
                'descripcion' => 'Taller de Instalaciones Eléctricas de Fuerza',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );

        // Crear cajones dentro de las ubicaciones
        $cajonesPanol = ['Cajon-A1', 'Cajon-A2', 'Gaveta-01', 'Gaveta-02', 'Estante-E1'];
        foreach ($cajonesPanol as $cod) {
            Cajon::firstOrCreate(
                ['location_id' => $panolCentral->id, 'codigo' => $cod],
                [
                    'descripcion' => "Compartimiento {$cod} en Pañol Central",
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ]
            );
        }

        $cajonesLab = ['Mesa-1-Cajon', 'Mesa-2-Cajon', 'Armario-Lab'];
        foreach ($cajonesLab as $cod) {
            Cajon::firstOrCreate(
                ['location_id' => $labElectronica->id, 'codigo' => $cod],
                [
                    'descripcion' => "Compartimiento {$cod} en Laboratorio E-301",
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ]
            );
        }
    }
}
