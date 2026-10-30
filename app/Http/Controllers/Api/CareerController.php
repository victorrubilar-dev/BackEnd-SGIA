<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCareerRequest;
use App\Http\Requests\UpdateCareerRequest;
use App\Http\Resources\CareerResource;
use App\Models\Career;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CareerController extends Controller
{
    /**
     * Display a listing of careers.
     *
     * Query params: level, search, per_page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Career::query();

        if ($request->filled('level')) {
            $query->where('level', $request->string('level'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->string('search').'%');
        }

        return CareerResource::collection($query->orderBy('name')->paginate($this->perPage($request)));
    }

    /**
     * Store a newly created career.
     */
    public function store(StoreCareerRequest $request): JsonResponse
    {
        $career = Career::create($request->validated() + [
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return (new CareerResource($career))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified career.
     */
    public function show(Career $career): CareerResource
    {
        return new CareerResource($career);
    }

    /**
     * Update the specified career.
     */
    public function update(UpdateCareerRequest $request, Career $career): CareerResource
    {
        $career->update($request->validated() + ['updated_by' => $request->user()->id]);

        return new CareerResource($career);
    }

    /**
     * Remove the specified career. Users keep their `area` text.
     */
    public function destroy(Career $career): Response
    {
        $career->delete();

        return response()->noContent();
    }
}
