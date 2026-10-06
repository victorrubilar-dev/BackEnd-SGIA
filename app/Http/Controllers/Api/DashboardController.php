<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    /**
     * REQ-14: Productos más solicitados (FU-06).
     *
     * GET /api/dashboard/top-products
     */
    public function topProducts(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard');

        return $this->respond($this->dashboard->topProducts($this->limit($request)));
    }

    /**
     * REQ-14: Insumos fungibles más solicitados (FU-06).
     *
     * GET /api/dashboard/top-supplies
     */
    public function topSupplies(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard');

        return $this->respond($this->dashboard->topSupplies($this->limit($request)));
    }

    /**
     * REQ-14: Distribución por carrera/área académica (FU-06).
     *
     * GET /api/dashboard/careers-distribution
     */
    public function careersDistribution(): JsonResponse
    {
        Gate::authorize('viewDashboard');

        return $this->respond($this->dashboard->careersDistribution());
    }

    /**
     * REQ-14: Equipos con menor rotación o sin uso (FU-06).
     *
     * GET /api/dashboard/least-demanded
     */
    public function leastDemanded(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard');

        return $this->respond($this->dashboard->leastDemanded($this->limit($request)));
    }

    /**
     * REQ-14: Docentes con más solicitudes de préstamo (FU-06).
     *
     * GET /api/dashboard/top-teachers
     */
    public function topTeachers(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard');

        return $this->respond($this->dashboard->topTeachers($this->limit($request)));
    }

    /**
     * Límite de filas por indicador (1..50, por defecto 10).
     */
    private function limit(Request $request): int
    {
        return max(1, min(50, $request->integer('limit', 10)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     */
    private function respond(array $data): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
