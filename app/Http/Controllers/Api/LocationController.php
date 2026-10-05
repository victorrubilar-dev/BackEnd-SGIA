<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationEntityRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
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
        $query = Location::with(['cajones', 'creator', 'updater'])->withCount('cajones');

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->string('tipo'));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', $search)
                  ->orWhere('sala', 'ilike', $search)
                  ->orWhere('descripcion', 'ilike', $search);
            });
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
        $locations = $query->orderBy('nombre')->paginate($perPage);

        return LocationResource::collection($locations);
    }

    /**
     * Store a newly created location in storage.
     */
    public function store(StoreLocationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['sala'] = $validated['nombre'];
        $validated['created_by'] = $request->user()?->id;
        $validated['updated_by'] = $request->user()?->id;

        $location = Location::create($validated);

        return (new LocationResource($location->load('cajones')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified location.
     */
    public function show(Location $location): LocationResource
    {
        return new LocationResource($location->load(['cajones', 'creator', 'updater'])->loadCount('cajones'));
    }

    /**
     * Update the specified location in storage.
     */
    public function update(UpdateLocationEntityRequest $request, Location $location): LocationResource
    {
        $validated = $request->validated();
        if (isset($validated['nombre'])) {
            $validated['sala'] = $validated['nombre'];
        }
        $validated['updated_by'] = $request->user()?->id;

        $location->update($validated);

        return new LocationResource($location->load(['cajones', 'creator', 'updater'])->loadCount('cajones'));
    }

    /**
     * Remove the specified location from storage.
     */
    public function destroy(Request $request, Location $location): JsonResponse
    {
        if (! in_array($request->user()?->role, ['AD-01', 'DIR-01'], true)) {
            return response()->json(['message' => 'No tienes permisos para eliminar ubicaciones.'], 403);
        }

        $location->delete();

        return response()->json([
            'message' => 'Ubicación eliminada exitosamente.',
        ]);
    }
}
