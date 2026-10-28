<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeleteCajonRequest;
use App\Http\Requests\StoreCajonRequest;
use App\Http\Requests\UpdateCajonRequest;
use App\Http\Resources\CajonResource;
use App\Models\Estante;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class CajonController extends Controller
{
    /**
     * Display a listing of cajones / estantes.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Estante::with(['sala.panol'])->withCount('equipos');

        if ($request->filled('location_id')) {
            $query->where('sala_id', $request->integer('location_id'));
        }

        if ($request->filled('sala_id')) {
            $query->where('sala_id', $request->integer('sala_id'));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('codigo_barras', 'ilike', $search)
                  ->orWhere('nombre', 'ilike', $search)
                  ->orWhereHas('sala', fn ($lq) => $lq->where('nombre_codigo', 'ilike', $search));
            });
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
        $cajones = $query->orderBy('codigo_barras')->paginate($perPage);

        return CajonResource::collection($cajones);
    }

    /**
     * Store a newly created cajon / estante in storage.
     */
    public function store(StoreCajonRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $salaId = $validated['sala_id'] ?? $validated['location_id'];
        $codigo = $validated['codigo_barras'] ?? $validated['codigo'] ?? ('EST-' . strtoupper(Str::random(6)));
        $nombre = $validated['nombre'] ?? $validated['codigo'] ?? $codigo;

        $cajon = Estante::create([
            'sala_id' => $salaId,
            'nombre' => $nombre,
            'codigo_barras' => $codigo,
        ]);

        return (new CajonResource($cajon->load('sala.panol')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified cajon / estante.
     */
    public function show(Estante $cajon): CajonResource
    {
        return new CajonResource($cajon->load(['sala.panol'])->loadCount('equipos'));
    }

    /**
     * Update the specified cajon / estante in storage.
     */
    public function update(UpdateCajonRequest $request, Estante $cajon): CajonResource
    {
        $validated = $request->validated();
        $payload = [];

        if (isset($validated['sala_id'])) {
            $payload['sala_id'] = $validated['sala_id'];
        } elseif (isset($validated['location_id'])) {
            $payload['sala_id'] = $validated['location_id'];
        }

        if (isset($validated['codigo_barras'])) {
            $payload['codigo_barras'] = $validated['codigo_barras'];
        } elseif (isset($validated['codigo'])) {
            $payload['codigo_barras'] = $validated['codigo'];
        }

        if (isset($validated['nombre'])) {
            $payload['nombre'] = $validated['nombre'];
        }

        $cajon->update($payload);

        return new CajonResource($cajon->fresh(['sala.panol'])->loadCount('equipos'));
    }

    /**
     * Remove the specified cajon / estante from storage.
     */
    public function destroy(Request $request, Estante $cajon): JsonResponse
    {
        if (! $request->user()?->hasRole(['AD-01', 'DIR-01'])) {
            return response()->json(['message' => 'No tienes permisos para eliminar cajones.'], 403);
        }

        $equiposCount = $cajon->equipos()->count();
        if ($equiposCount > 0) {
            return response()->json([
                'message' => "No es posible eliminar el estante/cajón \"{$cajon->nombre}\" porque tiene {$equiposCount} equipo(s) asignado(s). Debes reubicar o eliminar primero los equipos.",
                'error' => 'No se puede eliminar un estante con equipos asociados.',
                'equipos_count' => $equiposCount,
            ], 422);
        }

        try {
            $cajon->delete();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'No es posible eliminar el estante/cajón porque tiene registros relacionados en el sistema.',
            ], 422);
        }

        return response()->json([
            'message' => 'Cajón eliminado exitosamente.',
        ]);
    }

    /**
     * Bulk remove multiple cajones / estantes from storage.
     *
     * Permite borrado masivo enviando una lista de IDs (`ids`, `estante_ids`, o `cajon_ids`).
     * Si alguno tiene equipos asociados, reporta el conflicto para evitar el borrado no deseado.
     * Si se incluye `partial: true` o `ignore_conflicts: true`, elimina los que están libres
     * y reporta los bloqueados.
     */
    public function bulkDestroy(BulkDeleteCajonRequest $request): JsonResponse
    {
        if (! $request->user()?->hasRole(['AD-01', 'DIR-01'])) {
            return response()->json(['message' => 'No tienes permisos para eliminar cajones.'], 403);
        }

        $validated = $request->validated();
        $ids = array_values(array_unique($validated['ids']));
        $partial = $request->boolean('partial') || $request->boolean('ignore_conflicts');

        // Detectar cuáles tienen equipos vinculados
        $estantesConEquipos = Estante::whereIn('id', $ids)
            ->has('equipos')
            ->withCount('equipos')
            ->get();

        $blockedIds = $estantesConEquipos->pluck('id')->all();
        $deletableIds = array_values(array_diff($ids, $blockedIds));

        // Si hay conflictos y no se solicita borrado parcial, rechazar con 422 detallado
        if (! empty($blockedIds) && ! $partial) {
            $nombres = $estantesConEquipos->pluck('nombre')->join(', ');

            return response()->json([
                'message' => "No es posible eliminar los estantes seleccionados porque algunos tienen equipos asignados ({$nombres}). Debes reubicar o eliminar los equipos primero.",
                'error' => 'Existen estantes con equipos asociados.',
                'blocked_ids' => $blockedIds,
                'blocked_estantes' => $estantesConEquipos->map(fn ($e) => [
                    'id' => $e->id,
                    'nombre' => $e->nombre,
                    'codigo_barras' => $e->codigo_barras,
                    'equipos_count' => $e->equipos_count,
                ]),
                'deletable_ids' => $deletableIds,
            ], 422);
        }

        if (empty($deletableIds)) {
            return response()->json([
                'message' => 'No se pudo eliminar ningún estante porque todos los seleccionados tienen equipos asignados.',
                'blocked_ids' => $blockedIds,
                'deleted_count' => 0,
            ], 422);
        }

        try {
            $deletedCount = Estante::whereIn('id', $deletableIds)->delete();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Ocurrió un error de integridad referencial al intentar eliminar los estantes.',
            ], 422);
        }

        $responseData = [
            'message' => "{$deletedCount} estante(s) eliminado(s) exitosamente.",
            'deleted_ids' => $deletableIds,
            'deleted_count' => $deletedCount,
        ];

        if (! empty($blockedIds)) {
            $responseData['partial'] = true;
            $responseData['blocked_ids'] = $blockedIds;
            $responseData['blocked_estantes'] = $estantesConEquipos->map(fn ($e) => [
                'id' => $e->id,
                'nombre' => $e->nombre,
                'codigo_barras' => $e->codigo_barras,
                'equipos_count' => $e->equipos_count,
            ]);
        }

        return response()->json($responseData, 200);
    }
}
