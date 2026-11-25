<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Export\ExportLocationsRequest;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationEntityRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Reports\LocationsReport;
use App\Reports\ReportExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LocationController extends Controller
{
    /**
     * Display a listing of locations (salas, pañoles, talleres).
     *
     * Query params: tipo, storeroom_id, search, per_page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filtered(Location::with(['cajones', 'creator', 'updater'])->withCount('cajones'), $request);

        $perPage = $this->perPage($request);
        $locations = $query->orderBy('nombre')->paginate($perPage);

        return LocationResource::collection($locations);
    }

    /**
     * GET /api/locations/export: dónde está cada cosa, cajones y salas en Excel (docs/reportes-excel.md, ampliación A1).
     */
    public function export(ExportLocationsRequest $request, ReportExporter $exporter): Response
    {
        $query = $this->filtered(Location::query(), $request);
        if ($request->filled('location_id')) {
            $query->whereKey($request->integer('location_id'));
        }

        return $exporter->download(new LocationsReport($request, $query), $request);
    }

    /**
     * Filtros comunes del listado y del reporte: tipo, pañol y búsqueda.
     *
     * @param  Builder<Location>  $query
     * @return Builder<Location>
     */
    private function filtered(Builder $query, Request $request): Builder
    {
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->string('tipo'));
        }

        if ($request->filled('storeroom_id')) {
            $query->where('storeroom_id', $request->integer('storeroom_id'));
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', $search)
                    ->orWhere('sala', 'ilike', $search)
                    ->orWhere('descripcion', 'ilike', $search);
            });
        }

        return $query;
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
    public function destroy(Request $request, Location $location): Response|JsonResponse
    {
        if (! $request->user()?->hasRole('DIR-01')) {
            return response()->json(['message' => 'No tienes permisos para eliminar ubicaciones.'], 403);
        }

        $cajones = $location->cajones()->count();
        if ($cajones > 0) {
            return $this->unprocessable(
                "No es posible eliminar la ubicación \"{$location->nombre}\" porque tiene {$cajones} cajón(es). Elimínalos o reubícalos primero.",
                'location', ['cajones_count' => $cajones]
            );
        }

        $location->delete();

        return response()->noContent();
    }
}
