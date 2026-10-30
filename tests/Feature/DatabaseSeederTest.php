<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\Career;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeder_creates_admin_locations_and_cajones(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', ['email' => 'admin@sgia.cl', 'role' => User::ROLE_ADMIN]);
        $this->assertGreaterThan(0, Location::count());
        $this->assertGreaterThan(0, Cajon::count());
        $this->assertDatabaseHas('careers', ['name' => 'Analista Programador', 'level' => Career::LEVEL_TECHNICIAN]);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed();
        $locations = Location::count();
        $cajones = Cajon::count();

        $this->seed();

        $this->assertSame($locations, Location::count());
        $this->assertSame($cajones, Cajon::count());
    }

    public function test_seeder_creates_nothing_in_production_without_admin_password(): void
    {
        $this->app['env'] = 'production';
        config(['sgia.seed_admin_password' => null]);
        $before = User::where('email', 'admin@sgia.cl')->value('password');

        $this->app->make(DatabaseSeeder::class)->run();

        // No crea ni modifica al administrador sin contraseña explícita.
        $this->assertSame($before, User::where('email', 'admin@sgia.cl')->value('password'));
    }

    public function test_seeder_in_production_creates_only_admin_with_given_password(): void
    {
        $this->app['env'] = 'production';
        config(['sgia.seed_admin_password' => 'Clave-Segura-9']);
        $before = Location::count();

        $this->app->make(DatabaseSeeder::class)->run();

        $admin = User::where('email', 'admin@sgia.cl')->firstOrFail();
        $this->assertTrue(Hash::check('Clave-Segura-9', $admin->password));
        $this->assertSame($before, Location::count());
    }
}
