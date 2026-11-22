<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Auditoría de seguridad (docs/auditoria-seguridad.md, B2): las respuestas de la API no exponen SQL, clases,
 * archivos ni trazas, aunque APP_DEBUG esté activo (lo está en .env.testing).
 */
class ErrorLeakTest extends TestCase
{
    use DatabaseTransactions;

    private const LEAK_PATTERN = '/SQLSTATE|select \*|Illuminate|App\\\\\\\\|\.php|"exception"|"trace"|"file"/i';

    private function assertNoLeak(string $content): void
    {
        $this->assertDoesNotMatchRegularExpression(self::LEAK_PATTERN, $content);
    }

    public function test_non_numeric_or_out_of_range_ids_are_not_found_for_every_role(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_DIRECTOR, User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));

            foreach (['abc', "1'", '1.5', '99999999999999999999'] as $id) {
                foreach (["/api/products/{$id}", "/api/loans/{$id}", "/api/users/{$id}", "/api/incident-reports/{$id}/pdf"] as $uri) {
                    $response = $this->getJson($uri);
                    $response->assertNotFound();
                    $this->assertNoLeak($response->getContent());
                }
                foreach (["/api/devices/{$id}", "/api/tokens/{$id}", "/api/monitoring/sessions/{$id}"] as $uri) {
                    $response = $this->deleteJson($uri);
                    $this->assertContains($response->getStatusCode(), [403, 404], $uri);
                    $this->assertNoLeak($response->getContent());
                }
            }
        }
    }

    public function test_unexpected_exceptions_render_a_generic_spanish_message(): void
    {
        Route::middleware('api')->get('/api/_test/boom', fn () => throw new RuntimeException('SQLSTATE[42P01]: select * from "secret"'));

        $response = $this->getJson('/api/_test/boom');

        $response->assertStatus(500)->assertExactJson([
            'message' => 'Ocurrió un error inesperado. Intenta nuevamente o contacta al administrador.',
        ]);
    }

    public function test_http_errors_drop_debug_fields_and_use_spanish(): void
    {
        $response = $this->putJson('/api/login');
        $response->assertStatus(405)->assertExactJson(['message' => 'Método no permitido para este recurso.']);

        Route::middleware('api')->get('/api/_test/conflict', fn () => abort(409, 'Ya existe.'));
        $this->getJson('/api/_test/conflict')->assertStatus(409)->assertExactJson(['message' => 'Ya existe.']);
    }

    public function test_throttled_requests_answer_in_spanish_without_debug_fields(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]));

        for ($i = 0; $i < 10; $i++) {
            $this->putJson('/api/password', []);
        }
        $response = $this->putJson('/api/password', []);

        $response->assertStatus(429)->assertExactJson(['message' => 'Demasiadas solicitudes. Espera un momento e intenta de nuevo.']);
        $response->assertHeader('Retry-After');
    }
}
