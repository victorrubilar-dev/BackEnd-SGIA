<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = Usuario::with(['rol', 'panol', 'carreras'])
            ->where('email', $validated['email'])
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Credenciales inválidas.',
            ], 401);
        }

        if (! $user->activo) {
            return response()->json([
                'message' => 'Tu cuenta ha sido desactivada. Contacta al administrador.',
            ], 403);
        }

        $email = Str::transliterate(Str::lower($validated['email']));
        $throttleKey = $email . '|' . $request->ip();
        RateLimiter::clear('login:' . $throttleKey);
        RateLimiter::clear(md5('login' . $throttleKey));

        $expiresAt = match ($validated['device_name']) {
            'mobile' => now()->addDays(60),
            default => now()->addHours(8),
        };

        $token = $user->createToken($validated['device_name'], ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'message' => 'Login exitoso.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'nombre' => $user->nombre,
                'name' => $user->nombre,
                'email' => $user->email,
                'rol_id' => $user->rol_id,
                'rol' => $user->rol?->nombre,
                'role' => $user->role,
                'panol_id' => $user->panol_id,
                'panol' => $user->panol?->nombre,
                'activo' => (bool) $user->activo,
                'is_active' => (bool) $user->activo,
                'carreras' => $user->carreras->map(fn ($c) => [
                    'id' => $c->id,
                    'codigo' => $c->codigo,
                    'nombre' => $c->nombre,
                ]),
            ],
        ]);
    }

    public function me(Request $request): UserResource
    {
        $user = $request->user();
        if ($user instanceof Usuario) {
            $user->loadMissing(['rol', 'panol', 'carreras']);
        }

        return new UserResource($user);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => Hash::make($request->validated('password')),
        ])->save();

        // Revocar las sesiones anteriores por motivos de seguridad
        $currentTokenId = $user->currentAccessToken()?->id;
        $revokedCount = $user->tokens()
            ->when($currentTokenId, fn ($query) => $query->where('id', '!=', $currentTokenId))
            ->delete();

        return response()->json([
            'message' => 'Contraseña actualizada exitosamente.',
            'revoked_tokens' => $revokedCount,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada.',
        ]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $revokedCount = $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Todas las sesiones han sido cerradas exitosamente.',
            'revoked_count' => $revokedCount,
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

    public function revokeOtherTokens(Request $request): JsonResponse
    {
        $currentToken = $request->user()->currentAccessToken();
        $currentTokenId = $currentToken ? $currentToken->id : null;

        $revokedCount = $request->user()->tokens()
            ->when($currentTokenId, fn ($query) => $query->where('id', '!=', $currentTokenId))
            ->delete();

        return response()->json([
            'message' => 'Todas las demás sesiones han sido revocadas exitosamente.',
            'revoked_count' => $revokedCount,
        ]);
    }
}
