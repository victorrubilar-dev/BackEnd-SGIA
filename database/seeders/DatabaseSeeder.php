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
        $admin = User::updateOrCreate(
            ['email' => 'admin@sgia.cl'],
            [
                'name' => 'Administrador SGIA',
                'password' => Hash::make('Admin1234!'),
                'role' => User::ROLE_ADMIN,
                'area' => 'Informática',
                'is_active' => true,
            ]
        );

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
