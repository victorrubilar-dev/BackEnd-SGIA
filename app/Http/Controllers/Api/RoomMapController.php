<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EstanteResource;
use App\Http\Resources\ZonaResource;
use App\Models\Estante;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plano de una sala en un solo pedido (REQ-22, `docs/mapa-salas.md`).
 */
class RoomMapController extends Controller
{
    public function show(Request $request, Location $location): JsonResponse
    {
        $zonas = $location->zonas()->orderBy('nombre')->get();
        $estantes = Estante::where('location_id', $location->id)
            ->with(Estante::mapRelations())
            ->withExists(Estante::criticalStockExists())
            ->orderBy('codigo')
            ->get();

        return response()->json(['data' => [
            'location' => [
                'id' => $location->id,
                'code' => $location->code,
                'nombre' => $location->nombre ?? $location->sala,
                'tipo' => $location->tipo ?? Location::TIPO_SALA,
                'map_width_cm' => $location->mapWidth(),
                'map_height_cm' => $location->mapHeight(),
                'has_dimensions' => $location->hasMapDimensions(),
            ],
            'zonas' => ZonaResource::collection($zonas)->resolve($request),
            'estantes' => EstanteResource::collection($estantes)->resolve($request),
            'cajones_sin_estante_count' => $location->cajones()->whereNull('estante_id')->count(),
        ]]);
    }
}
