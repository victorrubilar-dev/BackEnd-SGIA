<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->teacher = User::factory()->create([
            'email' => 'teacher_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);
    }

    public function test_guest_cannot_access_user_management(): void
    {
        $this->getJson('/api/users')->assertStatus(401);
        $this->postJson('/api/users', [])->assertStatus(401);
        $this->getJson('/api/users/' . $this->teacher->id)->assertStatus(401);
        $this->putJson('/api/users/' . $this->teacher->id, [])->assertStatus(401);
        $this->deleteJson('/api/users/' . $this->teacher->id)->assertStatus(401);
        $this->patchJson('/api/users/' . $this->teacher->id . '/status', [])->assertStatus(401);
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/users')->assertStatus(403);
        $this->postJson('/api/users', [
            'name' => 'Nuevo Usuario',
            'email' => 'nuevo_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(403);
        $this->getJson('/api/users/' . $this->admin->id)->assertStatus(403);
        $this->putJson('/api/users/' . $this->admin->id, ['name' => 'Hack'])->assertStatus(403);
        $this->deleteJson('/api/users/' . $this->admin->id)->assertStatus(403);
        $this->patchJson('/api/users/' . $this->admin->id . '/status', ['is_active' => false])->assertStatus(403);
    }

    public function test_admin_can_list_users_with_filters_and_search(): void
    {
        Sanctum::actingAs($this->admin);

        $director = User::factory()->create([
            'name' => 'Roberto Director',
            'email' => 'director_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_DIRECTOR,
            'area' => 'Minas',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/users');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'email', 'role', 'area', 'is_active', 'created_at'],
            ],
            'links',
            'meta',
        ]);

        // Filter by role
        $roleFilterResponse = $this->getJson('/api/users?role=' . User::ROLE_DIRECTOR);
        $roleFilterResponse->assertStatus(200);
        $roleFilterResponse->assertJsonFragment(['email' => $director->email]);
        $roleFilterResponse->assertJsonMissing(['email' => $this->teacher->email]);

        // Search by name
        $searchResponse = $this->getJson('/api/users?search=Roberto');
        $searchResponse->assertStatus(200);
        $searchResponse->assertJsonFragment(['name' => 'Roberto Director']);
    }

    public function test_admin_can_create_a_user_successfully(): void
    {
        Sanctum::actingAs($this->admin);

        $email = 'docente_creado_' . uniqid() . '@inacap.cl';

        $response = $this->postJson('/api/users', [
            'name' => 'Profesor Juan Perez',
            'email' => $email,
            'role' => User::ROLE_TEACHER,
            'area' => 'Mecánica',
            'password' => 'SeguraClave123',
            'password_confirmation' => 'SeguraClave123',
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'data' => [
                'name' => 'Profesor Juan Perez',
                'email' => $email,
                'role' => User::ROLE_TEACHER,
                'area' => 'Mecánica',
                'is_active' => true,
            ],
        ]);

        $createdUser = User::where('email', $email)->first();
        $this->assertNotNull($createdUser);
        $this->assertTrue(Hash::check('SeguraClave123', $createdUser->password));
    }

    public function test_create_user_validates_required_fields_and_email_uniqueness(): void
    {
        Sanctum::actingAs($this->admin);

        // Missing fields
        $resRequired = $this->postJson('/api/users', []);
        $resRequired->assertStatus(422);
        $resRequired->assertJsonValidationErrors(['name', 'email', 'role', 'password']);

        // Duplicate email
        $resDuplicate = $this->postJson('/api/users', [
            'name' => 'Otro Nombre',
            'email' => $this->teacher->email,
            'role' => User::ROLE_WAREHOUSE,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);
        $resDuplicate->assertStatus(422);
        $resDuplicate->assertJsonValidationErrors(['email']);
    }

    public function test_create_user_validates_role_and_password_strength(): void
    {
        Sanctum::actingAs($this->admin);

        // Invalid role
        $resInvalidRole = $this->postJson('/api/users', [
            'name' => 'Estudiante Anonimo',
            'email' => 'student_' . uniqid() . '@inacap.cl',
            'role' => 'INVALID_ROLE',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);
        $resInvalidRole->assertStatus(422);
        $resInvalidRole->assertJsonValidationErrors(['role']);

        // Weak password (no letters/numbers or short)
        $resWeakPass = $this->postJson('/api/users', [
            'name' => 'Usuario Test',
            'email' => 'user_weak_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ]);
        $resWeakPass->assertStatus(422);
        $resWeakPass->assertJsonValidationErrors(['password']);

        // Password mismatch
        $resMismatch = $this->postJson('/api/users', [
            'name' => 'Usuario Test',
            'email' => 'user_mismatch_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'password' => 'Password123',
            'password_confirmation' => 'Password456',
        ]);
        $resMismatch->assertStatus(422);
        $resMismatch->assertJsonValidationErrors(['password']);
    }

    public function test_admin_can_view_user_details(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/users/' . $this->teacher->id);
        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'id' => $this->teacher->id,
                'email' => $this->teacher->email,
                'role' => User::ROLE_TEACHER,
            ],
        ]);
    }

    public function test_admin_can_update_user_without_changing_password(): void
    {
        Sanctum::actingAs($this->admin);

        $originalHash = $this->teacher->password;

        $response = $this->putJson('/api/users/' . $this->teacher->id, [
            'name' => 'Nombre Modificado',
            'area' => 'Área Modificada',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'id' => $this->teacher->id,
                'name' => 'Nombre Modificado',
                'area' => 'Área Modificada',
            ],
        ]);

        $this->teacher->refresh();
        $this->assertEquals($originalHash, $this->teacher->password);
    }

    public function test_admin_can_update_user_password(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/users/' . $this->teacher->id, [
            'password' => 'NewAdminAssignedPass123',
            'password_confirmation' => 'NewAdminAssignedPass123',
        ]);

        $response->assertStatus(200);

        $this->teacher->refresh();
        $this->assertTrue(Hash::check('NewAdminAssignedPass123', $this->teacher->password));
    }

    public function test_update_user_validates_email_uniqueness_excluding_self(): void
    {
        Sanctum::actingAs($this->admin);

        // Can keep their own email
        $resSelf = $this->putJson('/api/users/' . $this->teacher->id, [
            'email' => $this->teacher->email,
        ]);
        $resSelf->assertStatus(200);

        // Cannot take admin's email
        $resDuplicate = $this->putJson('/api/users/' . $this->teacher->id, [
            'email' => $this->admin->email,
        ]);
        $resDuplicate->assertStatus(422);
        $resDuplicate->assertJsonValidationErrors(['email']);
    }

    public function test_admin_can_delete_another_user(): void
    {
        Sanctum::actingAs($this->admin);

        $targetUser = User::factory()->create([
            'email' => 'to_delete_' . uniqid() . '@inacap.cl',
            'is_active' => true,
        ]);

        $response = $this->deleteJson('/api/users/' . $targetUser->id);
        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Usuario eliminado exitosamente.',
        ]);

        $this->assertDatabaseMissing('users', ['id' => $targetUser->id]);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->deleteJson('/api/users/' . $this->admin->id);
        $response->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_admin_can_toggle_user_status_and_deactivating_revokes_tokens(): void
    {
        Sanctum::actingAs($this->admin);

        $targetUser = User::factory()->create([
            'email' => 'status_test_' . uniqid() . '@inacap.cl',
            'is_active' => true,
        ]);

        $token = $targetUser->createToken('active_device');
        $this->assertEquals(1, $targetUser->tokens()->count());

        // Deactivate user
        $responseDeactivate = $this->patchJson('/api/users/' . $targetUser->id . '/status', [
            'is_active' => false,
        ]);

        $responseDeactivate->assertStatus(200);
        $responseDeactivate->assertJson([
            'message' => 'Estado de usuario actualizado exitosamente.',
            'user' => [
                'id' => $targetUser->id,
                'is_active' => false,
            ],
        ]);

        $targetUser->refresh();
        $this->assertFalse($targetUser->is_active);
        // Tokens should be revoked
        $this->assertEquals(0, $targetUser->tokens()->count());

        // Reactivate user
        $responseActivate = $this->patchJson('/api/users/' . $targetUser->id . '/status', [
            'is_active' => true,
        ]);

        $responseActivate->assertStatus(200);
        $targetUser->refresh();
        $this->assertTrue($targetUser->is_active);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->patchJson('/api/users/' . $this->admin->id . '/status', [
            'is_active' => false,
        ]);

        $response->assertStatus(403);

        $this->admin->refresh();
        $this->assertTrue($this->admin->is_active);
    }
}
