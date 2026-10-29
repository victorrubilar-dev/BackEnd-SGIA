<?php

namespace Tests\Feature;

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
}
