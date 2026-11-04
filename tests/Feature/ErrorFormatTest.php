<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ErrorFormatTest extends TestCase
{
    use DatabaseTransactions;

    public function test_unauthenticated_error_has_spanish_message(): void
    {
        $this->getJson('/api/me')->assertUnauthorized()->assertExactJson(['message' => 'No autenticado.']);
    }

    public function test_policy_denial_has_spanish_message(): void
    {
        $teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]);
        Sanctum::actingAs($teacher);

        $this->postJson('/api/products', [])->assertForbidden()->assertJsonPath('message', 'No tienes permisos para realizar esta acción.');
    }

    public function test_not_found_does_not_leak_internal_class_names(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/products/99999999')->assertNotFound();

        $response->assertExactJson(['message' => 'Recurso no encontrado.']);
        $this->assertStringNotContainsString(Product::class, $response->getContent());
    }

    public function test_validation_error_has_message_and_errors(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/users', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['name']]);
    }

    public function test_business_rule_errors_also_carry_errors(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        Sanctum::actingAs($admin);

        $location = Location::factory()->create();
        Cajon::factory()->create(['location_id' => $location->id]);
        $this->deleteJson("/api/locations/{$location->id}")
            ->assertUnprocessable()->assertJsonStructure(['message', 'errors' => ['location'], 'cajones_count']);

        $unit = EquipmentUnit::factory()->create(['status' => 'prestado']);
        $this->deleteJson("/api/equipment-units/{$unit->id}")
            ->assertUnprocessable()->assertJsonStructure(['message', 'errors' => ['equipment_unit']]);
        $this->patchJson("/api/equipment-units/{$unit->id}/status", ['status' => 'disponible'])
            ->assertUnprocessable()->assertJsonStructure(['message', 'errors' => ['status']]);

        $loan = Loan::factory()->processed()->create();
        $this->postJson("/api/loans/{$loan->id}/approve")
            ->assertUnprocessable()->assertJsonStructure(['message', 'errors' => ['status'], 'allowed_transitions']);
    }

    /**
     * Toda respuesta 422 armada a mano debe pasar por Controller::unprocessable() (o incluir `errors`).
     */
    public function test_no_hand_built_422_without_errors(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getRealPath() === app_path('Http/Controllers/Controller.php')) {
                continue;
            }
            $code = file_get_contents($file->getRealPath());
            if (preg_match('/\],\s*422\)/', $code) && ! str_contains($code, "'errors' =>")) {
                $offenders[] = str_replace(base_path().'/', '', $file->getRealPath());
            }
        }

        $this->assertSame([], $offenders, 'Respuestas 422 sin `errors`: usa $this->unprocessable().');
    }
}
