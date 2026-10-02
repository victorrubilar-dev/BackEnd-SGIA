<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ActiveUserTest extends TestCase
{
    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive_test@inacap.cl',
            'password' => Hash::make('password123'),
            'role' => 'PRO-01',
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'inactive_test@inacap.cl',
            'password' => 'password123',
            'device_name' => 'web',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Tu cuenta ha sido desactivada. Contacta al administrador.',
        ]);

        $user->tokens()->delete();
        $user->delete();
    }

    public function test_active_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'active_test@inacap.cl',
            'password' => Hash::make('password123'),
            'role' => 'PRO-01',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'active_test@inacap.cl',
            'password' => 'password123',
            'device_name' => 'web',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'token',
            'token_type',
            'user' => ['id', 'name', 'email', 'role', 'area', 'is_active'],
        ]);
        $response->assertJsonPath('user.is_active', true);

        $user->tokens()->delete();
        $user->loginRecords()->delete();
        $user->delete();
    }

    public function test_middleware_blocks_inactive_user_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'token_inactive_test@inacap.cl',
            'password' => Hash::make('password123'),
            'role' => 'PRO-01',
            'is_active' => false,
        ]);

        $token = $user->createToken('web')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user');

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Tu cuenta ha sido desactivada. Contacta al administrador.',
        ]);

        $this->assertEquals(0, $user->tokens()->count());

        $user->delete();
    }
}
