<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationEntityRequest;
use App\Http\Resources\LocationResource;
use App\Models\Panol;
use App\Models\Sala;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationController extends Controller
{
    /**
     * Display a listing of locations (salas, pañoles, talleres).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Sala::with(['panol', 'estantes'])->withCount('estantes');

        if ($request->filled('search')) {
            $search = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('nombre_codigo', 'ilike', $search)
                  ->orWhere('edificio', 'ilike', $search);
            });
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
        $locations = $query->orderBy('nombre_codigo')->paginate($perPage);

        return LocationResource::collection($locations);
    }

    /**
     * Store a newly created location in storage.
     */
    public function store(StoreLocationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $panolId = $validated['panol_id'] ?? $request->user()?->panol_id ?? Panol::first()?->id ?? 1;

        $location = Sala::create([
            'panol_id' => $panolId,
            'nombre_codigo' => $validated['nombre_codigo'] ?? $validated['nombre'],
            'edificio' => $validated['edificio'] ?? null,
            'piso' => $validated['piso'] ?? null,
        ]);

        return (new LocationResource($location->load(['panol', 'estantes'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified location.
     */
    public function show(Sala $location): LocationResource
    {
        return new LocationResource($location->load(['panol', 'estantes'])->loadCount('estantes'));
    }

    /**
     * Update the specified location in storage.
     */
    public function update(UpdateLocationEntityRequest $request, Sala $location): LocationResource
    {
        $validated = $request->validated();
        $payload = [];

        if (isset($validated['nombre_codigo'])) {
            $payload['nombre_codigo'] = $validated['nombre_codigo'];
        } elseif (isset($validated['nombre'])) {
            $payload['nombre_codigo'] = $validated['nombre'];
        }

        if (array_key_exists('edificio', $validated)) {
            $payload['edificio'] = $validated['edificio'];
        }

        if (array_key_exists('piso', $validated)) {
            $payload['piso'] = $validated['piso'];
        }

        if (isset($validated['panol_id'])) {
            $payload['panol_id'] = $validated['panol_id'];
        }

        $location->update($payload);

        return new LocationResource($location->fresh(['panol', 'estantes'])->loadCount('estantes'));
    }

    /**
     * Remove the specified location from storage.
     */
    public function destroy(Request $request, Sala $location): JsonResponse
    {
        if (! $request->user()?->hasRole(['AD-01', 'DIR-01'])) {
            return response()->json(['message' => 'No tienes permisos para eliminar ubicaciones.'], 403);
        }

        $estantesCount = $location->estantes()->count();
        if ($estantesCount > 0) {
            return response()->json([
                'message' => "No es posible eliminar la sala \"{$location->nombre_codigo}\" porque tiene {$estantesCount} estante(s) o cajón(es) asociado(s). Debes reubicar o eliminar primero los estantes.",
                'error' => 'No se puede eliminar una sala con estantes asociados.',
                'estantes_count' => $estantesCount,
            ], 422);
        }

        try {
            $location->delete();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'No es posible eliminar la sala porque tiene registros relacionados en el sistema.',
            ], 422);
        }

        return response()->json([
            'message' => 'Ubicación eliminada exitosamente.',
        ]);
    }
}
