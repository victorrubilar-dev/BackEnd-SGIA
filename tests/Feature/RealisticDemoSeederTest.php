<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RealisticDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los datos de presentación deben poder leerse por la API (antes `/novedades` daba 500 por `problems` mal formados).
 */
class RealisticDemoSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeded_incident_reports_are_listed(): void
    {
        Notification::fake();
        $this->seed(RealisticDemoSeeder::class);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]));

        $this->getJson('/api/incident-reports?per_page=100')
            ->assertOk()
            ->assertJsonStructure(['data' => [['problems' => [['type', 'description', 'label']]]]]);
    }
}
