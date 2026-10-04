<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_cannot_change_password(): void
    {
        $response = $this->putJson('/api/password', [
            'current_password' => 'oldpassword123',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ]);

        $response->assertStatus(401);
    }

    public function test_user_cannot_change_password_with_incorrect_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/password', [
            'current_password' => 'WrongPassword123',
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['current_password']);
    }

    public function test_user_cannot_change_password_if_new_password_is_weak(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        // Less than 8 characters
        $resShort = $this->putJson('/api/password', [
            'current_password' => 'CorrectPassword123',
            'password' => 'Pass1',
            'password_confirmation' => 'Pass1',
        ]);
        $resShort->assertStatus(422)->assertJsonValidationErrors(['password']);

        // Only letters, no numbers
        $resNoNumbers = $this->putJson('/api/password', [
            'current_password' => 'CorrectPassword123',
            'password' => 'OnlyLettersPassword',
            'password_confirmation' => 'OnlyLettersPassword',
        ]);
        $resNoNumbers->assertStatus(422)->assertJsonValidationErrors(['password']);

        // Only numbers, no letters
        $resNoLetters = $this->putJson('/api/password', [
            'current_password' => 'CorrectPassword123',
            'password' => '1234567890',
            'password_confirmation' => '1234567890',
        ]);
        $resNoLetters->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_user_cannot_change_password_if_confirmation_does_not_match(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/password', [
            'current_password' => 'CorrectPassword123',
            'password' => 'NewPassword456',
            'password_confirmation' => 'DifferentPassword456',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_user_cannot_change_password_to_the_same_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/password', [
            'current_password' => 'CorrectPassword123',
            'password' => 'CorrectPassword123',
            'password_confirmation' => 'CorrectPassword123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_user_can_successfully_change_password_and_other_tokens_are_revoked(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('InitialPassword123'),
            'is_active' => true,
        ]);

        // Create multiple tokens
        $token1 = $user->createToken('web');
        $token2 = $user->createToken('mobile');
        $token3 = $user->createToken('tablet');

        $this->assertEquals(3, $user->tokens()->count());

        // Make request with token1 as current bearer token
        $response = $this->withHeader('Authorization', 'Bearer ' . $token1->plainTextToken)
            ->putJson('/api/password', [
                'current_password' => 'InitialPassword123',
                'password' => 'UpdatedSecurePassword456',
                'password_confirmation' => 'UpdatedSecurePassword456',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Contraseña actualizada exitosamente.',
            'revoked_tokens' => 2,
        ]);

        // User's password was updated in DB
        $user->refresh();
        $this->assertTrue(Hash::check('UpdatedSecurePassword456', $user->password));
        $this->assertFalse(Hash::check('InitialPassword123', $user->password));

        // Token 2 and 3 should be revoked, Token 1 remains active
        $this->assertEquals(1, $user->tokens()->count());
        $this->assertEquals($token1->accessToken->id, $user->tokens()->first()->id);
    }

    public function test_user_can_change_password_via_post_change_password_alias(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('InitialPassword123'),
            'is_active' => true,
        ]);

        $token = $user->createToken('web');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/change-password', [
                'current_password' => 'InitialPassword123',
                'password' => 'BrandNewPassword789',
                'password_confirmation' => 'BrandNewPassword789',
            ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertTrue(Hash::check('BrandNewPassword789', $user->password));
    }
}
