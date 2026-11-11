<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockAlertResource;
use App\Models\StockAlert;
use App\Services\StockAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockAlertController extends Controller
{
    /**
     * Display a listing of critical and warning stock alerts (REQ-06).
     */
    public function index(Request $request, StockAlertService $stockAlerts): AnonymousResourceCollection
    {
        // Las alertas abiertas se igualan al stock real del inventario antes de listarlas.
        $stockAlerts->reconcile();

        $query = StockAlert::with(['product', 'product.location', 'product.supplier']);

        if ($request->filled('alert_type')) {
            $query->where('alert_type', $request->string('alert_type'));
        }

        // Por defecto mostrar solo no resueltas salvo que se indique lo contrario
        if ($request->has('is_resolved')) {
            $query->where('is_resolved', $request->boolean('is_resolved'));
        } else {
            $query->where('is_resolved', false);
        }

        $perPage = $this->perPage($request);
        $alerts = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        // Totales reales, independientes de filtros y paginación (campana y cinta de la bandeja).
        $open = StockAlert::where('is_resolved', false);

        return StockAlertResource::collection($alerts)->additional([
            'counts' => [
                'active' => (clone $open)->count(),
                'critical' => (clone $open)->where('alert_type', StockAlert::TYPE_CRITICAL)->count(),
                'warning' => (clone $open)->where('alert_type', StockAlert::TYPE_WARNING)->count(),
                'resolved' => StockAlert::where('is_resolved', true)->count(),
            ],
        ]);
    }

    /**
     * Mark an alert as resolved.
     */
    public function resolve(StockAlert $stockAlert, StockAlertService $stockAlerts): JsonResponse
    {
        $stockAlerts->resolve($stockAlert);

        return response()->json([
            'message' => 'Alerta marcada como resuelta exitosamente.',
            'alert' => new StockAlertResource($stockAlert->load('product')),
        ]);
    }
}
