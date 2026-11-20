<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreZonaRequest;
use App\Http\Requests\UpdateZonaRequest;
use App\Http\Resources\ZonaResource;
use App\Models\Zona;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Zonas del plano de una sala (REQ-22, `docs/mapa-salas.md`).
 */
class ZonaController extends Controller
{
    /**
     * Query params: location_id. Sin paginar: son pocas por sala.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $zonas = Zona::query()
            ->when($request->filled('location_id'), fn ($query) => $query->where('location_id', $request->integer('location_id')))
            ->orderBy('location_id')
            ->orderBy('nombre')
            ->get();

        return ZonaResource::collection($zonas);
    }

    public function store(StoreZonaRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['nombre'] = trim($validated['nombre']);
        $validated['created_by'] = $request->user()?->id;
        $validated['updated_by'] = $request->user()?->id;

        return (new ZonaResource(Zona::create($validated)))->response()->setStatusCode(201);
    }

    public function update(UpdateZonaRequest $request, Zona $zona): ZonaResource
    {
        $validated = $request->validated();
        if (isset($validated['nombre'])) {
            $validated['nombre'] = trim($validated['nombre']);
        }
        $validated['updated_by'] = $request->user()?->id;

        $zona->update($validated);

        return new ZonaResource($zona);
    }

    /** Sus estantes quedan sin zona (`nullOnDelete`). */
    public function destroy(Zona $zona): Response
    {
        $zona->delete();

        return response()->noContent();
    }
}
