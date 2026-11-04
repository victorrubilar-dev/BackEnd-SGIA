<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStoreroomRequest;
use App\Http\Requests\UpdateStoreroomRequest;
use App\Http\Resources\StoreroomResource;
use App\Models\Storeroom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Pañoles: lectura para todos los autenticados; escritura solo AD-01.
 */
class StoreroomController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Storeroom::class);

        $query = Storeroom::withCount(['locations', 'users']);

        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $search)->orWhere('code', 'ilike', $search));
        }

        return StoreroomResource::collection($query->orderBy('name')->paginate($this->perPage($request)));
    }

    public function show(Storeroom $storeroom): StoreroomResource
    {
        Gate::authorize('view', $storeroom);

        return new StoreroomResource($storeroom->loadCount(['locations', 'users']));
    }

    public function store(StoreStoreroomRequest $request): JsonResponse
    {
        $storeroom = Storeroom::create($request->validated() + [
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return (new StoreroomResource($storeroom->loadCount(['locations', 'users'])))->response()->setStatusCode(201);
    }

    public function update(UpdateStoreroomRequest $request, Storeroom $storeroom): StoreroomResource
    {
        $storeroom->update($request->validated() + ['updated_by' => $request->user()->id]);

        return new StoreroomResource($storeroom->loadCount(['locations', 'users']));
    }

    /**
     * DELETE /api/storerooms/{storeroom} — `422` si tiene salas, usuarios o préstamos.
     */
    public function destroy(Storeroom $storeroom): Response|JsonResponse
    {
        Gate::authorize('delete', $storeroom);

        $blockers = array_filter([
            'salas' => $storeroom->locations()->exists(),
            'usuarios' => $storeroom->users()->exists(),
            'préstamos' => $storeroom->loans()->exists(),
        ]);

        if ($blockers !== []) {
            $message = 'El pañol tiene '.implode(', ', array_keys($blockers)).' asociados y no se puede eliminar.';

            return $this->unprocessable($message, 'storeroom');
        }

        $storeroom->delete();

        return response()->noContent();
    }
}
