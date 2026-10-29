<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeleteCajonRequest;
use App\Http\Requests\StoreCajonRequest;
use App\Http\Requests\UpdateCajonRequest;
use App\Http\Resources\CajonResource;
use App\Models\Cajon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CajonController extends Controller
{
    /**
     * Display a listing of cajones.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Cajon::with(['location', 'creator', 'updater'])->withCount('products');

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->integer('location_id'));
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($search) {
                $q->where('codigo', 'ilike', $search)
                    ->orWhere('descripcion', 'ilike', $search)
                    ->orWhereHas('location', fn ($lq) => $lq->where('nombre', 'ilike', $search));
            });
        }

        $perPage = $this->perPage($request);
        $cajones = $query->orderBy('codigo')->paginate($perPage);

        return CajonResource::collection($cajones);
    }

    /**
     * Store a newly created cajon in storage.
     */
    public function store(StoreCajonRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['created_by'] = $request->user()?->id;
        $validated['updated_by'] = $request->user()?->id;

        $cajon = Cajon::create($validated);

        return (new CajonResource($cajon->load('location')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified cajon.
     */
    public function show(Cajon $cajon): CajonResource
    {
        return new CajonResource($cajon->load(['location', 'creator', 'updater'])->loadCount('products'));
    }

    /**
     * Update the specified cajon in storage.
     */
    public function update(UpdateCajonRequest $request, Cajon $cajon): CajonResource
    {
        $validated = $request->validated();
        $validated['updated_by'] = $request->user()?->id;

        $cajon->update($validated);

        return new CajonResource($cajon->load(['location', 'creator', 'updater'])->loadCount('products'));
    }

    /**
     * Remove the specified cajon from storage.
     */
    public function destroy(Request $request, Cajon $cajon): Response|JsonResponse
    {
        if (! $request->user()?->hasRole(['AD-01', 'DIR-01'])) {
            return response()->json(['message' => 'No tienes permisos para eliminar cajones.'], 403);
        }

        $products = $cajon->products()->count();
        if ($products > 0) {
            return response()->json([
                'message' => "No es posible eliminar el cajón \"{$cajon->codigo}\" porque tiene {$products} producto(s) asignado(s). Reubícalos primero.",
                'products_count' => $products,
            ], 422);
        }

        $cajon->delete();

        return response()->noContent();
    }

    /**
     * Borrado masivo. Los cajones con productos no se borran: sin `partial`
     * se rechaza todo (422); con `partial: true` se borran los libres y se
     * informan los bloqueados.
     */
    public function bulkDestroy(BulkDeleteCajonRequest $request): JsonResponse
    {
        $ids = array_values(array_unique($request->validated('ids')));

        $blocked = Cajon::whereIn('id', $ids)->withCount('products')->has('products')->get();
        $blockedIds = $blocked->pluck('id')->all();
        $deletableIds = array_values(array_diff($ids, $blockedIds));
        $detail = $blocked->map(fn (Cajon $c) => ['id' => $c->id, 'codigo' => $c->codigo, 'products_count' => $c->products_count]);

        if ($blockedIds !== [] && ! $request->boolean('partial')) {
            return response()->json([
                'message' => 'No es posible eliminar los cajones seleccionados porque algunos tienen productos asignados ('.$blocked->pluck('codigo')->join(', ').').',
                'blocked_ids' => $blockedIds,
                'blocked_cajones' => $detail,
                'deletable_ids' => $deletableIds,
            ], 422);
        }

        if ($deletableIds === []) {
            return response()->json([
                'message' => 'No se eliminó ningún cajón: todos los seleccionados tienen productos asignados.',
                'blocked_ids' => $blockedIds,
                'blocked_cajones' => $detail,
                'deleted_count' => 0,
            ], 422);
        }

        $deleted = Cajon::whereIn('id', $deletableIds)->delete();

        return response()->json([
            'message' => "{$deleted} cajón(es) eliminado(s).",
            'deleted_ids' => $deletableIds,
            'deleted_count' => $deleted,
            'partial' => $blockedIds !== [],
            'blocked_ids' => $blockedIds,
            'blocked_cajones' => $detail,
        ]);
    }
}
