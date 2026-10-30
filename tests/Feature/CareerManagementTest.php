<?php

namespace Tests\Feature;

use App\Models\Career;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CareerManagementTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_admin_manages_careers_with_audit_fields(): void
    {
        $admin = $this->actingAsRole(User::ROLE_ADMIN);

        $id = $this->postJson('/api/careers', ['name' => 'Técnico en Redes', 'level' => 'tecnico'])
            ->assertCreated()
            ->assertJsonPath('data.level_label', 'Técnico de Nivel Superior')
            ->json('data.id');

        $this->assertDatabaseHas('careers', ['id' => $id, 'created_by' => $admin->id, 'updated_by' => $admin->id]);

        $this->patchJson("/api/careers/{$id}", ['level' => 'profesional'])
            ->assertOk()->assertJsonPath('data.level', 'profesional');

        $this->getJson('/api/careers?level=profesional&search=redes')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/careers/{$id}")->assertOk()->assertJsonPath('data.name', 'Técnico en Redes');

        $this->deleteJson("/api/careers/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('careers', ['id' => $id]);
    }

    public function test_director_can_read_but_not_write(): void
    {
        $career = Career::factory()->create();
        $this->actingAsRole(User::ROLE_DIRECTOR);

        $this->getJson('/api/careers')->assertOk();
        $this->postJson('/api/careers', ['name' => 'X', 'level' => 'tecnico'])->assertForbidden();
        $this->patchJson("/api/careers/{$career->id}", ['level' => 'profesional'])->assertForbidden();
        $this->deleteJson("/api/careers/{$career->id}")->assertForbidden();
    }

    public function test_warehouse_and_teacher_cannot_access(): void
    {
        foreach ([User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/careers')->assertForbidden();
        }
    }

    public function test_validation(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);
        $existing = Career::factory()->create();

        $this->postJson('/api/careers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'El nombre de la carrera es obligatorio.', 'level' => 'El nivel de la carrera es obligatorio.']);

        $this->postJson('/api/careers', ['name' => $existing->name, 'level' => 'magister'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'level']);

        // Mantener su propio nombre no choca con la regla unique.
        $this->patchJson("/api/careers/{$existing->id}", ['name' => $existing->name])->assertOk();
    }
}
