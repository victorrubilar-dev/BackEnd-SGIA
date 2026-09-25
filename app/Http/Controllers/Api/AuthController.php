<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\LoginRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Credenciales inválidas.',
            ], 401);
        }

        $expiresAt = match ($validated['device_name']) {
            'mobile' => now()->addDays(60),
            default => now()->addHours(8),
        };

        $token = $user->createToken($validated['device_name'], ['*'], $expiresAt)->plainTextToken;

        $now = now();
        $user->forceFill(['last_login_at' => $now])->save();

        LoginRecord::create([
            'user_id' => $user->id,
            'device_type' => $validated['device_name'],
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'logged_in_at' => $now,
        ]);

        return response()->json([
            'message' => 'Login exitoso.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->only(['id', 'name', 'email', 'role', 'area']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada.',
        ]);
    }

    public function tokens(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->orderByDesc('created_at')->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'device_name' => $token->name,
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ]);

        return response()->json([
            'tokens' => $tokens,
        ]);
    }

    public function revokeToken(Request $request, int $tokenId): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($tokenId)->first();

        if (! $token) {
            return response()->json([
                'message' => 'Token no encontrado.',
            ], 404);
        }

        $deviceName = $token->name;
        $token->delete();

        return response()->json([
            'message' => "Sesión del dispositivo {$deviceName} revocada.",
        ]);
    }
}