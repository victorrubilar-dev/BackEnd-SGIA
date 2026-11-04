<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderUserChartsRequest;
use App\Http\Requests\StoreUserChartRequest;
use App\Http\Requests\UpdateUserChartRequest;
use App\Http\Resources\ChartTemplateResource;
use App\Http\Resources\UserChartResource;
use App\Models\ChartTemplate;
use App\Models\UserChart;
use App\Services\UserChartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Dashboard personalizado del usuario autenticado (REQ-15).
 */
class UserChartController extends Controller
{
    /**
     * GET /api/chart-templates — plantillas sembradas (sin paginar).
     */
    public function templates(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', UserChart::class);

        return ChartTemplateResource::collection(ChartTemplate::orderBy('id')->get());
    }

    /**
     * GET /api/me/charts — gráficos propios por posición (`active=1` solo los visibles).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', UserChart::class);

        $query = $request->user()->charts()->with('template')->orderBy('position');

        if ($request->boolean('active')) {
            $query->where('is_active', true);
        }

        return UserChartResource::collection($query->get());
    }

    public function store(StoreUserChartRequest $request, UserChartService $charts): JsonResponse
    {
        $chart = $charts->create($request->user(), $request->validated());

        return (new UserChartResource($chart->load('template')))->response()->setStatusCode(201);
    }

    public function show(UserChart $userChart): UserChartResource
    {
        Gate::authorize('view', $userChart);

        return new UserChartResource($userChart->load('template'));
    }

    public function update(UpdateUserChartRequest $request, UserChart $userChart, UserChartService $charts): UserChartResource
    {
        return new UserChartResource($charts->update($userChart, $request->validated())->load('template'));
    }

    /**
     * PUT /api/me/charts/positions — `positions: [{id, position}]` en una transacción.
     */
    public function reorder(ReorderUserChartsRequest $request, UserChartService $charts): AnonymousResourceCollection
    {
        $charts->reorder($request->user(), $request->validated('positions'));

        return UserChartResource::collection($request->user()->charts()->with('template')->orderBy('position')->get());
    }

    public function destroy(UserChart $userChart): Response
    {
        Gate::authorize('delete', $userChart);

        $userChart->delete();

        return response()->noContent();
    }
}
