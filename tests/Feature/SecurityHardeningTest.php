<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Auditoría de seguridad (docs/auditoria-seguridad.md): H-08, H-09 y H-12. */
class SecurityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    public function test_role_is_checked_before_model_binding_so_ids_cannot_be_enumerated(): void
    {
        $existing = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]));

        $this->getJson("/api/users/{$existing->id}")->assertForbidden();
        $this->getJson('/api/users/999999999')->assertForbidden();

        $loan = Loan::factory()->create();
        $this->postJson("/api/loans/{$loan->id}/approve")->assertForbidden();
        $this->postJson('/api/loans/999999999/approve')->assertForbidden();
    }

    public function test_authorized_role_still_gets_not_found_for_missing_ids(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));

        $this->getJson('/api/users/999999999')->assertNotFound()->assertExactJson(['message' => 'Recurso no encontrado.']);
    }

    public function test_array_query_parameters_are_rejected_with_422(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]));

        foreach (['/api/loans?status[]=a', '/api/equipment-units?status[]=a', '/api/incident-reports?status[0]=a', '/api/products?search[]=x'] as $uri) {
            $this->getJson($uri)
                ->assertUnprocessable()
                ->assertJsonStructure(['message', 'errors']);
        }

        $this->getJson('/api/loans?status=pendiente')->assertOk();
    }

    public function test_api_responses_carry_security_headers(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]));

        foreach ([$this->getJson('/api/me'), $this->getJson('/api/products/999999999'), $this->getJson('/api/users')] as $response) {
            $response->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'no-referrer');
        }
    }
}
