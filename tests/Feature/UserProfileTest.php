<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    public function test_authenticated_user_can_retrieve_profile_via_me_endpoint(): void
    {
        $user = User::factory()->create([
            'name' => 'Profesor Roberto',
            'email' => 'roberto@inacap.cl',
            'password' => Hash::make('password123'),
            'role' => 'PRO-01',
            'area' => 'Informática y Ciberseguridad',
            'is_active' => true,
            'last_login_at' => now(),
        ]);

        $token = $user->createToken('web')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/me');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'email',
                'role',
                'area',
                'is_active',
                'last_login_at',
                'created_at',
                'updated_at',
            ],
        ]);

        $response->assertJson([
            'data' => [
                'id' => $user->id,
                'name' => 'Profesor Roberto',
                'email' => 'roberto@inacap.cl',
                'role' => 'PRO-01',
                'area' => 'Informática y Ciberseguridad',
                'is_active' => true,
            ],
        ]);

        $response->assertJsonMissing([
            'password' => $user->password,
            'remember_token' => $user->remember_token,
        ]);

        $user->tokens()->delete();
        $user->delete();
    }

    public function test_user_endpoint_alias_returns_same_profile_resource(): void
    {
        $user = User::factory()->create([
            'email' => 'alias_test@inacap.cl',
            'role' => 'DIR-01',
            'is_active' => true,
        ]);

        $token = $user->createToken('web')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user');

        $response->assertStatus(200);
        $response->assertJsonPath('data.email', 'alias_test@inacap.cl');
        $response->assertJsonPath('data.role', 'DIR-01');

        $user->tokens()->delete();
        $user->delete();
    }

    public function test_unauthenticated_request_to_me_returns_401(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    }
}
