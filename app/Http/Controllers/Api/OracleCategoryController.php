<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOracleCategoryRequest;
use App\Http\Resources\OracleCategoryResource;
use App\Models\OracleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OracleCategoryController extends Controller
{
    /**
     * Query params: search. Catálogo completo (sin paginar) para el selector de `oracle_category_id`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = OracleCategory::query();

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->string('search').'%');
        }

        return OracleCategoryResource::collection($query->orderBy('name')->get());
    }

    public function store(StoreOracleCategoryRequest $request): JsonResponse
    {
        $category = OracleCategory::create([
            'name' => $request->validated('name'),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return (new OracleCategoryResource($category))->response()->setStatusCode(201);
    }
}
