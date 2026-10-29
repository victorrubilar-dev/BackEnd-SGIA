<?php

namespace App\Services;

use App\Models\Cajon;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProductService
{
    /**
     * Aplica los filtros del listado de productos (búsqueda, área, estado,
     * proveedor, ubicación y stock crítico).
     */
    public function applyFilters(Builder $query, Request $request): Builder
    {
        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', $search)
                    ->orWhere('barcode', 'ilike', $search)
                    ->orWhere('description', 'ilike', $search);
            });
        }

        if ($request->filled('area')) {
            $query->where('area', $request->string('area'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('cajon_id')) {
            $query->where('cajon_id', $request->integer('cajon_id'));
        }

        if ($request->filled('location_id')) {
            $locationId = $request->integer('location_id');
            $query->where(function ($q) use ($locationId) {
                $q->where('location_id', $locationId)
                    ->orWhereHas('cajon', fn ($cq) => $cq->where('location_id', $locationId));
            });
        }

        // Filtro por ubicación: sala/ubicacion y/o cajón
        if ($request->filled('sala') || $request->filled('cajon')) {
            $query->where(function ($q) use ($request) {
                if ($request->filled('sala')) {
                    $sala = '%'.$request->string('sala').'%';
                    $q->whereHas('location', fn ($lq) => $lq->where('sala', 'ilike', $sala)->orWhere('nombre', 'ilike', $sala))
                        ->orWhereHas('cajon.location', fn ($lq) => $lq->where('sala', 'ilike', $sala)->orWhere('nombre', 'ilike', $sala));
                }
                if ($request->filled('cajon')) {
                    $cajon = '%'.$request->string('cajon').'%';
                    $q->whereHas('cajon', fn ($cq) => $cq->where('codigo', 'ilike', $cajon))
                        ->orWhereHas('location', fn ($lq) => $lq->where('cajon', 'ilike', $cajon));
                }
            });
        }

        if ($request->boolean('critical_only')) {
            $query->whereColumn('quantity', '<=', 'stock_minimo');
        }

        return $query;
    }

    /**
     * Traduce `sala` + `cajon` (texto) o `cajon_id` a `location_id` / `cajon_id`
     * y retira de los datos los campos auxiliares.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function resolveLocation(array $validated, ?string $description, ?int $userId): array
    {
        if (! empty($validated['sala']) && ! empty($validated['cajon'])) {
            [$location, $cajon] = $this->findOrCreateSalaCajon($validated['sala'], $validated['cajon'], $description, $userId);
            $validated['location_id'] = $location->id;
            $validated['cajon_id'] = $cajon->id;
        } elseif (! empty($validated['cajon_id'])) {
            $cajon = Cajon::find($validated['cajon_id']);
            if ($cajon) {
                $validated['location_id'] = $cajon->location_id;
            }
        }

        unset($validated['sala'], $validated['cajon'], $validated['descripcion']);

        return $validated;
    }

    /**
     * @return array{0: Location, 1: Cajon}
     */
    public function findOrCreateSalaCajon(string $sala, string $codigo, ?string $description, ?int $userId): array
    {
        $location = Location::firstOrCreate(
            ['nombre' => $sala],
            [
                'sala' => $sala,
                'tipo' => 'sala',
                'descripcion' => $description,
                'created_by' => $userId,
            ]
        );
        $cajon = Cajon::firstOrCreate(
            ['location_id' => $location->id, 'codigo' => $codigo],
            [
                'descripcion' => $description,
                'created_by' => $userId,
            ]
        );

        return [$location, $cajon];
    }
}
