<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDashboardTemplateRequest;
use App\Http\Requests\UpdateDashboardTemplateRequest;
use App\Http\Resources\DashboardTemplateResource;
use App\Models\DashboardTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Plantillas de dashboard con nombre del usuario autenticado (REQ-15). Los gráficos de cada una
 * se manejan con /api/me/charts y `dashboard_template_id`.
 */
class DashboardTemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', DashboardTemplate::class);

        return DashboardTemplateResource::collection(
            $request->user()->dashboardTemplates()->withCount('charts')->orderBy('name')->get(),
        );
    }

    public function store(StoreDashboardTemplateRequest $request): JsonResponse
    {
        $template = $request->user()->dashboardTemplates()->create($request->validated());

        return (new DashboardTemplateResource($template->loadCount('charts')))->response()->setStatusCode(201);
    }

    public function show(DashboardTemplate $dashboardTemplate): DashboardTemplateResource
    {
        Gate::authorize('view', $dashboardTemplate);

        return new DashboardTemplateResource($dashboardTemplate->loadCount('charts'));
    }

    public function update(UpdateDashboardTemplateRequest $request, DashboardTemplate $dashboardTemplate): DashboardTemplateResource
    {
        $dashboardTemplate->update($request->validated());

        return new DashboardTemplateResource($dashboardTemplate->loadCount('charts'));
    }

    /** Borra la plantilla junto con sus gráficos. */
    public function destroy(DashboardTemplate $dashboardTemplate): Response
    {
        Gate::authorize('delete', $dashboardTemplate);

        $dashboardTemplate->delete();

        return response()->noContent();
    }
}
