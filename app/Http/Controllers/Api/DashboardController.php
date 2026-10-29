<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Support\DashboardPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * REQ-14: Productos más solicitados (FU-06).
     *
     * GET /api/dashboard/top-products
     */
    public function topProducts(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard');

        return $this->respond($this->dashboard->topProducts($this->limit($request), $this->period($request)));
    }

    /**
     * REQ-14: Insumos fungibles más solicitados (FU-06).
     *
     * GET /api/dashboard/top-supplies
     */
    public function topSupplies(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard');

        return $this->respond($this->dashboard->topSupplies($this->limit($request), $this->period($request)));
    }

    /**
     * REQ-14: Distribución por carrera/área académica (FU-06).
     *
     * GET /api/dashboard/careers-distribution
     */
    public function careersDistribution(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard');

        return $this->respond($this->dashboard->careersDistribution($this->period($request)));
    }

    /**
     * REQ-14: Equipos con menor rotación o sin uso (FU-06).
     *
     * GET /api/dashboard/least-demanded
     */
    public function leastDemanded(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard');

        return $this->respond($this->dashboard->leastDemanded($this->limit($request), $this->period($request)));
    }

    /**
     * REQ-14: Docentes con más solicitudes de préstamo (FU-06).
     *
     * GET /api/dashboard/top-teachers
     */
    public function topTeachers(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard');

        return $this->respond($this->dashboard->topTeachers($this->limit($request), $this->period($request)));
    }

    /**
     * REQ-14: KPIs ejecutivos (FU-06). Período por defecto: últimos 30 días.
     *
     * GET /api/dashboard/kpis
     */
    public function kpis(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard');

        $period = $this->period($request) ?? DashboardPeriod::resolve('LAST_30_DAYS');

        return response()->json(['data' => $this->dashboard->kpis($period), 'generated_at' => now()->toIso8601String()]);
    }

    /**
     * REQ-14: Préstamos por docente y asignatura (FU-06).
     *
     * GET /api/dashboard/loans-by-teacher
     */
    public function loansByTeacher(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard');

        return $this->respond($this->dashboard->loansByTeacher($this->limit($request), $this->period($request)));
    }

    /**
     * Período opcional: `period`, `from`/`to` o `since` (alias de `from`).
     * Sin ninguno, los rankings cubren todo el historial.
     */
    private function period(Request $request): ?DashboardPeriod
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'since' => ['nullable', 'date'],
        ]);

        return DashboardPeriod::resolve(
            $request->input('period'),
            $request->input('from', $request->input('since')),
            $request->input('to')
        );
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
