<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_success_for_web_client(): void
    {
        $user = User::factory()->create([
            'email' => 'docente_web_' . uniqid() . '@inacap.cl',
            'password' => Hash::make('DocentePassword123'),
            'role' => 'PRO-01',
            'area' => 'Informática',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'DocentePassword123',
            'device_name' => 'web',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'token',
            'token_type',
            'user' => ['id', 'name', 'email', 'role', 'area', 'is_active'],
        ]);

        $token = $user->tokens()->where('name', 'web')->first();
        $this->assertNotNull($token);
        $this->assertTrue($token->expires_at->between(now()->addHours(7), now()->addHours(9)));

        // LoginRecord created
        $this->assertDatabaseHas('login_records', [
            'user_id' => $user->id,
            'device_type' => 'web',
        ]);

        // last_login_at updated
        $user->refresh();
        $this->assertNotNull($user->last_login_at);
    }

    public function test_login_success_for_mobile_client(): void
    {
        $user = User::factory()->create([
            'email' => 'docente_mob_' . uniqid() . '@inacap.cl',
            'password' => Hash::make('DocentePassword123'),
            'role' => 'PRO-01',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'DocentePassword123',
            'device_name' => 'mobile',
        ]);

        $response->assertStatus(200);

        $token = $user->tokens()->where('name', 'mobile')->first();
        $this->assertNotNull($token);
        $this->assertTrue($token->expires_at->between(now()->addDays(59), now()->addDays(61)));
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'docente_err_' . uniqid() . '@inacap.cl',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'WrongPassword123',
            'device_name' => 'web',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Credenciales inválidas.',
        ]);
    }

    public function test_login_fails_for_inactive_user(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive_usr_' . uniqid() . '@inacap.cl',
            'password' => Hash::make('DocentePassword123'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'DocentePassword123',
            'device_name' => 'web',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Tu cuenta ha sido desactivada. Contacta al administrador.',
        ]);
    }

    public function test_ensure_user_is_active_middleware_blocks_inactive_user(): void
    {
        $user = User::factory()->create([
            'email' => 'blocked_tok_' . uniqid() . '@inacap.cl',
            'is_active' => false,
        ]);

        $token = $user->createToken('web')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/me');

        $response->assertStatus(403);
    }

    public function test_logout_deletes_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'logout_tst_' . uniqid() . '@inacap.cl',
            'is_active' => true,
        ]);

        $token1 = $user->createToken('session_1');
        $token2 = $user->createToken('session_2');

        $this->assertEquals(2, $user->tokens()->count());

        $response = $this->withHeader('Authorization', 'Bearer ' . $token1->plainTextToken)
            ->postJson('/api/logout');

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Sesión cerrada.',
        ]);

        // token1 was deleted, token2 remains
        $remainingTokens = $user->tokens()->get();
        $this->assertCount(1, $remainingTokens);
        $this->assertEquals($token2->accessToken->id, $remainingTokens->first()->id);
    }

    public function test_list_and_revoke_tokens_by_device(): void
    {
        $user = User::factory()->create([
            'email' => 'devices_tst_' . uniqid() . '@inacap.cl',
            'is_active' => true,
        ]);

        $tokenA = $user->createToken('web_chrome');
        $tokenB = $user->createToken('mobile_pixel');

        // List tokens
        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenA->plainTextToken)
            ->getJson('/api/tokens');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'tokens');
        $response->assertJsonFragment(['device_name' => 'web_chrome']);
        $response->assertJsonFragment(['device_name' => 'mobile_pixel']);

        // Revoke tokenB by id
        $deleteResponse = $this->withHeader('Authorization', 'Bearer ' . $tokenA->plainTextToken)
            ->deleteJson('/api/tokens/' . $tokenB->accessToken->id);

        $deleteResponse->assertStatus(200);
        $deleteResponse->assertJson([
            'message' => 'Sesión del dispositivo mobile_pixel revocada.',
        ]);

        $this->assertEquals(1, $user->tokens()->count());
        $this->assertFalse($user->tokens()->where('name', 'mobile_pixel')->exists());
    }

    public function test_get_me_returns_authenticated_user_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Profesor Carlos',
            'email' => 'carlos_' . uniqid() . '@inacap.cl',
            'role' => 'PRO-01',
            'area' => 'Electricidad',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me');

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'id' => $user->id,
                'name' => 'Profesor Carlos',
                'email' => $user->email,
                'role' => 'PRO-01',
                'area' => 'Electricidad',
                'is_active' => true,
            ],
        ]);
    }
}
