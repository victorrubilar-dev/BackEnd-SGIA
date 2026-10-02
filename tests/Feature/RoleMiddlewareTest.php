<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'active', 'role:AD-01'])
            ->get('/api/test-admin-only', fn () => response()->json(['message' => 'admin area']));

        Route::middleware(['auth:sanctum', 'active', 'role:DIR-01,PAN-01'])
            ->get('/api/test-multi-role', fn () => response()->json(['message' => 'warehouse and director area']));
    }

    public function test_user_model_role_helper_methods(): void
    {
        $admin = new User(['role' => 'AD-01']);
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isDirector());
        $this->assertFalse($admin->isWarehouse());
        $this->assertFalse($admin->isTeacher());
        $this->assertTrue($admin->hasRole(['AD-01', 'DIR-01']));
        $this->assertTrue($admin->hasRole('AD-01'));

        $director = new User(['role' => 'DIR-01']);
        $this->assertTrue($director->isDirector());
        $this->assertFalse($director->isAdmin());

        $warehouse = new User(['role' => 'PAN-01']);
        $this->assertTrue($warehouse->isWarehouse());
        $this->assertFalse($warehouse->isTeacher());

        $teacher = new User(['role' => 'PRO-01']);
        $this->assertTrue($teacher->isTeacher());
        $this->assertFalse($teacher->isWarehouse());
    }

    public function test_user_with_required_role_can_access_route(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin_test@inacap.cl',
            'password' => Hash::make('password123'),
            'role' => 'AD-01',
            'is_active' => true,
        ]);

        $token = $admin->createToken('web')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/test-admin-only');

        $response->assertStatus(200);
        $response->assertJson(['message' => 'admin area']);

        $admin->tokens()->delete();
        $admin->delete();
    }

    public function test_user_without_required_role_is_denied_with_403(): void
    {
        $teacher = User::factory()->create([
            'email' => 'teacher_test@inacap.cl',
            'password' => Hash::make('password123'),
            'role' => 'PRO-01',
            'is_active' => true,
        ]);

        $token = $teacher->createToken('web')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/test-admin-only');

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Acceso denegado. No tienes los permisos necesarios para realizar esta acción.',
        ]);

        $teacher->tokens()->delete();
        $teacher->delete();
    }

    public function test_user_with_one_of_multiple_allowed_roles_can_access(): void
    {
        $warehouse = User::factory()->create([
            'email' => 'warehouse_test@inacap.cl',
            'password' => Hash::make('password123'),
            'role' => 'PAN-01',
            'is_active' => true,
        ]);

        $token = $warehouse->createToken('web')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/test-multi-role');

        $response->assertStatus(200);
        $response->assertJson(['message' => 'warehouse and director area']);

        $warehouse->tokens()->delete();
        $warehouse->delete();
    }

    public function test_unauthenticated_request_to_role_route_is_rejected(): void
    {
        $response = $this->getJson('/api/test-admin-only');

        $response->assertStatus(401);
    }
}
