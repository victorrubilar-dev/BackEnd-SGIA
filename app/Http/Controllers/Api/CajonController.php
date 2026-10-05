<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCajonRequest;
use App\Http\Requests\UpdateCajonRequest;
use App\Http\Resources\CajonResource;
use App\Models\Cajon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
            $search = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('codigo', 'ilike', $search)
                  ->orWhere('descripcion', 'ilike', $search)
                  ->orWhereHas('location', fn ($lq) => $lq->where('nombre', 'ilike', $search));
            });
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
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
    public function destroy(Request $request, Cajon $cajon): JsonResponse
    {
        if (! in_array($request->user()?->role, ['AD-01', 'DIR-01'], true)) {
            return response()->json(['message' => 'No tienes permisos para eliminar cajones.'], 403);
        }

        $cajon->delete();

        return response()->json([
            'message' => 'Cajón eliminado exitosamente.',
        ]);
    }
}
