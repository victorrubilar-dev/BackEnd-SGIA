<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeviceTokenRequest;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeviceTokenController extends Controller
{
    /**
     * Registra el token de push del dispositivo para el usuario autenticado.
     * Si el token ya existía (p. ej. otra cuenta en el mismo teléfono) pasa a este usuario.
     *
     * POST /api/devices
     */
    public function store(StoreDeviceTokenRequest $request): JsonResponse
    {
        $device = DeviceToken::updateOrCreate(
            ['token' => $request->validated('token')],
            ['user_id' => $request->user()->id, 'platform' => $request->validated('platform')]
        );

        return response()->json(['id' => $device->id, 'platform' => $device->platform], 201);
    }

    /**
     * Elimina un token propio (al cerrar sesión en el dispositivo).
     *
     * DELETE /api/devices/{id}
     */
    public function destroy(Request $request, int $id): Response
    {
        $request->user()->deviceTokens()->whereKey($id)->firstOrFail()->delete();

        return response()->noContent();
    }
}
