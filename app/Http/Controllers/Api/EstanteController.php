<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEstanteRequest;
use App\Http\Requests\UpdateEstanteRequest;
use App\Http\Resources\EstanteResource;
use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\Estante;
use App\Models\Location;
use App\Models\Product;
use App\Models\Zona;
use App\Services\RoomMapService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Estantes del plano de una sala (REQ-22, `docs/mapa-salas.md`). No son los cajones.
 */
class EstanteController extends Controller
{
    /** Estados de unidad que se informan en el contenido (las dadas de baja no se cuentan). */
    private const CONTENT_STATUSES = [
        EquipmentUnit::STATUS_AVAILABLE,
        EquipmentUnit::STATUS_ON_LOAN,
        EquipmentUnit::STATUS_IN_REPAIR,
    ];

    /**
     * Query params: location_id, search, per_page (hasta 200).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Estante::with(Estante::mapRelations())->withExists(Estante::criticalStockExists());

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->integer('location_id'));
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(fn (Builder $q) => $q->where('codigo', 'ilike', $search)->orWhere('nombre', 'ilike', $search));
        }

        $perPage = max(1, min(200, $request->integer('per_page', 50)));

        return EstanteResource::collection($query->orderBy('location_id')->orderBy('codigo')->paginate($perPage));
    }

    public function store(StoreEstanteRequest $request, RoomMapService $map): JsonResponse
    {
        $validated = $request->validated();
        $validated['rotation'] = (int) ($validated['rotation'] ?? 0);

        if (! isset($validated['x'], $validated['y'])) {
            $location = Location::findOrFail($validated['location_id']);
            $zona = isset($validated['zona_id']) ? Zona::find($validated['zona_id']) : null;
            $slot = $map->placeEstante($location, $validated['width'], $validated['depth'], $validated['rotation'], $zona);

            if ($slot === null) {
                return $this->unprocessable('No hay espacio libre en la sala para un estante de esas medidas.', 'x');
            }
            [$validated['x'], $validated['y']] = $slot;
        }

        $validated['created_by'] = $request->user()?->id;
        $validated['updated_by'] = $request->user()?->id;

        $estante = Estante::create($validated);

        return (new EstanteResource($estante->loadMapSummary()))->response()->setStatusCode(201);
    }

    public function show(Estante $estante): EstanteResource
    {
        return new EstanteResource($estante->loadMapSummary());
    }

    public function update(UpdateEstanteRequest $request, Estante $estante): EstanteResource
    {
        $validated = $request->validated();
        $validated['updated_by'] = $request->user()?->id;

        $estante->update($validated);

        return new EstanteResource($estante->loadMapSummary());
    }

    public function destroy(Estante $estante): Response|JsonResponse
    {
        $cajones = $estante->cajones()->count();
        if ($cajones > 0) {
            return $this->unprocessable(
                "El estante tiene {$cajones} cajones; muévelos antes de eliminarlo.",
                'estante', ['cajones_count' => $cajones]
            );
        }

        $estante->delete();

        return response()->noContent();
    }

    /**
     * Contenido para el pop-up del mapa: cajones con sus insumos y unidades agrupadas por producto.
     */
    public function contents(Request $request, Estante $estante): JsonResponse
    {
        // Sobre los cajones ya cargados (con conteos y orden), no se vuelven a pedir.
        $estante->loadMapSummary()->load(['zona', 'location']);
        $estante->cajones->load([
            'products' => fn ($query) => $query->where('type', Product::TYPE_SUPPLY)->where('is_active', true)->orderBy('name'),
            'equipmentUnits' => fn ($query) => $query->where('status', '!=', EquipmentUnit::STATUS_WRITTEN_OFF)->with('product:id,name,type'),
        ]);

        return response()->json(['data' => [
            'estante' => (new EstanteResource($estante))->resolve($request),
            'zona' => $estante->zona ? ['id' => $estante->zona->id, 'nombre' => $estante->zona->nombre] : null,
            'location' => [
                'id' => $estante->location->id,
                'code' => $estante->location->code,
                'nombre' => $estante->location->nombre ?? $estante->location->sala,
            ],
            'cajones' => $estante->cajones->map(fn (Cajon $cajon) => [
                'id' => $cajon->id,
                'codigo' => $cajon->codigo,
                'barcode' => $cajon->barcode,
                'descripcion' => $cajon->descripcion,
                'lleno' => (bool) $cajon->lleno,
                'productos' => $cajon->products->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'type' => $product->type,
                    'barcode' => $product->barcode,
                    'quantity' => $product->quantity,
                    'stock_minimo' => $product->stock_minimo,
                    'is_critical' => $product->isCriticalStock(),
                ])->values()->all(),
                'unidades' => $cajon->equipmentUnits->groupBy('product_id')
                    ->map(function ($units) {
                        /** @var EquipmentUnit $first */
                        $first = $units->first();
                        $byStatus = array_fill_keys(self::CONTENT_STATUSES, 0);
                        foreach ($units as $unit) {
                            $byStatus[$unit->status] = ($byStatus[$unit->status] ?? 0) + 1;
                        }

                        return [
                            'product_id' => $first->product_id,
                            'product_name' => $first->product?->name,
                            'type' => $first->product?->type,
                            'total' => $units->count(),
                            'by_status' => $byStatus,
                        ];
                    })
                    ->sortBy('product_name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()->all(),
            ])->values()->all(),
        ]]);
    }
}
