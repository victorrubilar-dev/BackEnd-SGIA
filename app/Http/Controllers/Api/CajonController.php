<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCajonRequest;
use App\Http\Requests\UpdateCajonRequest;
use App\Http\Resources\CajonResource;
use App\Models\Estante;
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

        $cajon->delete();

        return response()->json([
            'message' => 'Cajón eliminado exitosamente.',
        ]);
    }
}
