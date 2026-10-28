<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
