<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Export\ExportStockAlertsRequest;
use App\Http\Resources\StockAlertResource;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\StockAlert;
use App\Models\StockAlertDismissal;
use App\Reports\ReportExporter;
use App\Reports\StockAlertsReport;
use App\Services\StockAlertService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class StockAlertController extends Controller
{
    /**
     * Display a listing of critical and warning stock alerts (REQ-06).
     */
    public function index(Request $request, StockAlertService $stockAlerts): AnonymousResourceCollection
    {
        // Las alertas abiertas se igualan al stock real del inventario antes de listarlas.
        $this->validateDateRange($request);
        $request->validate(['search' => ['nullable', 'string', 'max:120']], ['search.max' => 'La búsqueda es demasiado larga.']);
        $stockAlerts->reconcile();

        $query = $this->filtered(StockAlert::with(['product', 'product.location', 'product.supplier']), $request);
        $excludeDismissed = $request->boolean('exclude_dismissed');
        $userId = (int) $request->user()?->id;

        $perPage = $this->perPage($request);
        $alerts = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        // Solicitud de compra abierta (no cancelada ni facturada) de cada producto, en una sola consulta.
        $openRequests = PurchaseRequestItem::query()
            ->whereIn('product_id', $alerts->pluck('product_id'))
            ->whereHas('purchaseRequest', fn ($q) => $q->whereNotIn('status', [PurchaseRequest::STATUS_CANCELLED, PurchaseRequest::STATUS_INVOICED]))
            ->with('purchaseRequest:id,code,status')
            ->orderBy('id')
            ->get()
            ->keyBy('product_id');
        $alerts->each(fn (StockAlert $a) => $a->setRelation('openPurchaseRequest', $openRequests->get($a->product_id)?->purchaseRequest));

        // Totales reales, independientes de filtros y paginación (campana y cinta de la bandeja).
        $open = StockAlert::where('is_resolved', false)
            ->when($excludeDismissed, fn ($q) => $q->notDismissedBy($userId));

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
     * GET /api/alerts/export: reporte Excel con los filtros de la bandeja (docs/reportes-excel.md).
     */
    public function export(ExportStockAlertsRequest $request, StockAlertService $stockAlerts, ReportExporter $exporter): Response|JsonResponse
    {
        $stockAlerts->reconcile();
        $query = $this->filtered(StockAlert::with(['product.career', 'product.cajon.location', 'product.location']), $request);

        return $exporter->respond(new StockAlertsReport($request, $query), $request);
    }

    /**
     * Filtros comunes del listado y del reporte: `exclude_dismissed`, `alert_type`, `is_resolved` (sin él, solo
     * las abiertas), `search` (nombre o código del producto) y `from`/`to` sobre la fecha de la alerta.
     *
     * @param  Builder<StockAlert>  $query
     * @return Builder<StockAlert>
     */
    private function filtered(Builder $query, Request $request): Builder
    {
        // La campana pide `exclude_dismissed=1`: sin las alertas que este usuario quitó (lista y totales).
        if ($request->boolean('exclude_dismissed')) {
            $query->notDismissedBy((int) $request->user()?->id);
        }

        if ($request->filled('alert_type')) {
            $query->where('alert_type', $request->string('alert_type'));
        }

        // Por defecto mostrar solo no resueltas salvo que se indique lo contrario
        if ($request->has('is_resolved')) {
            $query->where('is_resolved', $request->boolean('is_resolved'));
        } else {
            $query->where('is_resolved', false);
        }

        if ($request->filled('search')) {
            $like = '%'.$request->string('search').'%';
            $query->whereHas('product', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('barcode', 'ilike', $like)));
        }

        if ($request->filled('from')) {
            $query->where('stock_alerts.created_at', '>=', $request->date('from')->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('stock_alerts.created_at', '<=', $request->date('to')->endOfDay());
        }

        return $query;
    }

    /**
     * Quita la alerta de la campana de este usuario, sin resolverla (docs/exportaciones-y-campana.md). Idempotente.
     */
    public function dismiss(Request $request, StockAlert $stockAlert): Response
    {
        StockAlertDismissal::firstOrCreate([
            'user_id' => $request->user()->id,
            'stock_alert_id' => $stockAlert->id,
        ]);

        return response()->noContent();
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
