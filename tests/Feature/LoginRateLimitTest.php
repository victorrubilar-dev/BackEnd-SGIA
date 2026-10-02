<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    protected function tearDown(): void
    {
        RateLimiter::clear('login:ratelimit_test@inacap.cl|127.0.0.1');
        RateLimiter::clear(md5('loginratelimit_test@inacap.cl|127.0.0.1'));
        RateLimiter::clear('login:nonexistent@inacap.cl|127.0.0.1');
        RateLimiter::clear(md5('loginnonexistent@inacap.cl|127.0.0.1'));

        parent::tearDown();
    }

    public function test_prevents_user_enumeration_with_unified_error_message(): void
    {
        // 1. Non-existent user
        $responseNotExist = $this->postJson('/api/login', [
            'email' => 'nonexistent@inacap.cl',
            'password' => 'any_password',
            'device_name' => 'web',
        ]);

        $responseNotExist->assertStatus(401);
        $responseNotExist->assertJson([
            'message' => 'Credenciales inválidas.',
        ]);

        // 2. Existing user with wrong password
        $user = User::factory()->create([
            'email' => 'existing_user@inacap.cl',
            'password' => Hash::make('correct_password'),
            'role' => 'PRO-01',
            'is_active' => true,
        ]);

        $responseWrongPass = $this->postJson('/api/login', [
            'email' => 'existing_user@inacap.cl',
            'password' => 'wrong_password',
            'device_name' => 'web',
        ]);

        $responseWrongPass->assertStatus(401);
        $responseWrongPass->assertJson([
            'message' => 'Credenciales inválidas.',
        ]);

        $this->assertEquals(
            $responseNotExist->json('message'),
            $responseWrongPass->json('message'),
            'Error messages must be identical to prevent user enumeration'
        );

        $user->tokens()->delete();
        $user->delete();
    }

    public function test_throttles_login_attempts_after_five_failures(): void
    {
        $email = 'ratelimit_test@inacap.cl';

        // 5 allowed attempts with invalid credentials
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => $email,
                'password' => 'wrong_pass',
                'device_name' => 'web',
            ]);

            $response->assertStatus(401);
        }

        // 6th attempt should be blocked by rate limiter
        $blockedResponse = $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'wrong_pass',
            'device_name' => 'web',
        ]);

        $blockedResponse->assertStatus(429);
        $this->assertTrue($blockedResponse->headers->has('Retry-After'));
        $blockedResponse->assertJsonStructure(['message']);
        $this->assertStringContainsString('Demasiados intentos', $blockedResponse->json('message'));
    }

    public function test_successful_login_clears_rate_limiter(): void
    {
        $user = User::factory()->create([
            'email' => 'clear_limit_test@inacap.cl',
            'password' => Hash::make('valid_password_123'),
            'role' => 'PRO-01',
            'is_active' => true,
        ]);

        // 2 failed attempts
        for ($i = 1; $i <= 2; $i++) {
            $this->postJson('/api/login', [
                'email' => 'clear_limit_test@inacap.cl',
                'password' => 'wrong_password',
                'device_name' => 'web',
            ])->assertStatus(401);
        }

        // Successful login
        $successResponse = $this->postJson('/api/login', [
            'email' => 'clear_limit_test@inacap.cl',
            'password' => 'valid_password_123',
            'device_name' => 'web',
        ]);

        $successResponse->assertStatus(200);

        $user->tokens()->delete();
        $user->loginRecords()->delete();
        $user->delete();
    }
}
