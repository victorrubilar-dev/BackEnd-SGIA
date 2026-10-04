<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TokenLifecycleTest extends TestCase
{
    public function test_sanctum_configuration_respects_per_token_expiration(): void
    {
        $this->assertNull(
            Config::get('sanctum.expiration'),
            'sanctum.expiration must be null to respect individual token expires_at values'
        );
    }

    public function test_web_and_mobile_tokens_have_distinct_expiration_periods(): void
    {
        $user = User::factory()->create([
            'email' => 'token_duration_test@inacap.cl',
            'password' => Hash::make('password123'),
            'role' => 'PRO-01',
            'is_active' => true,
        ]);

        // Web login -> 8 hours
        $webResponse = $this->postJson('/api/login', [
            'email' => 'token_duration_test@inacap.cl',
            'password' => 'password123',
            'device_name' => 'web',
        ]);
        $webResponse->assertStatus(200);

        // Mobile login -> 60 days
        $mobileResponse = $this->postJson('/api/login', [
            'email' => 'token_duration_test@inacap.cl',
            'password' => 'password123',
            'device_name' => 'mobile',
        ]);
        $mobileResponse->assertStatus(200);

        $webToken = $user->tokens()->where('name', 'web')->first();
        $mobileToken = $user->tokens()->where('name', 'mobile')->first();

        $this->assertNotNull($webToken->expires_at);
        $this->assertNotNull($mobileToken->expires_at);

        // Web token should expire around 8 hours (between 7 and 9 hours)
        $this->assertTrue(
            $webToken->expires_at->between(now()->addHours(7), now()->addHours(9))
        );

        // Mobile token should expire around 60 days (between 59 and 61 days)
        $this->assertTrue(
            $mobileToken->expires_at->between(now()->addDays(59), now()->addDays(61))
        );

        $user->tokens()->delete();
        $user->loginRecords()->delete();
        $user->delete();
    }

    public function test_revoke_other_tokens_revokes_all_sessions_except_current(): void
    {
        $user = User::factory()->create([
            'email' => 'revoke_others_test@inacap.cl',
            'password' => Hash::make('password123'),
            'role' => 'PRO-01',
            'is_active' => true,
        ]);

        $token1 = $user->createToken('current_device')->plainTextToken;
        $token2 = $user->createToken('other_laptop')->plainTextToken;
        $token3 = $user->createToken('other_phone')->plainTextToken;

        $this->assertCount(3, $user->tokens);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token1)
            ->postJson('/api/tokens/revoke-others');

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Todas las demás sesiones han sido revocadas exitosamente.',
            'revoked_count' => 2,
        ]);

        $this->assertCount(1, $user->fresh()->tokens);
        $this->assertEquals('current_device', $user->fresh()->tokens->first()->name);

        $user->tokens()->delete();
        $user->delete();
    }

    public function test_logout_all_revokes_every_session(): void
    {
        $user = User::factory()->create([
            'email' => 'logout_all_test@inacap.cl',
            'password' => Hash::make('password123'),
            'role' => 'PRO-01',
            'is_active' => true,
        ]);

        $token1 = $user->createToken('device_a')->plainTextToken;
        $token2 = $user->createToken('device_b')->plainTextToken;

        $this->assertCount(2, $user->tokens);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token1)
            ->postJson('/api/logout-all');

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Todas las sesiones han sido cerradas exitosamente.',
            'revoked_count' => 2,
        ]);

        $this->assertCount(0, $user->fresh()->tokens);

        $user->delete();
    }

    public function test_prune_expired_sanctum_tokens_command(): void
    {
        $user = User::factory()->create([
            'email' => 'prune_test@inacap.cl',
            'password' => Hash::make('password123'),
            'role' => 'PRO-01',
            'is_active' => true,
        ]);

        // Token expired 48 hours ago
        $expiredToken = $user->createToken('old_expired', ['*'], now()->subHours(48));

        // Token still valid
        $validToken = $user->createToken('still_valid', ['*'], now()->addHours(8));

        $this->assertCount(2, $user->tokens);

        // Run sanctum:prune-expired with 24 hours threshold
        $exitCode = Artisan::call('sanctum:prune-expired', ['--hours' => 24]);
        $this->assertEquals(0, $exitCode);

        $remainingTokens = $user->fresh()->tokens;
        $this->assertCount(1, $remainingTokens);
        $this->assertEquals('still_valid', $remainingTokens->first()->name);

        $user->tokens()->delete();
        $user->delete();
    }
}
