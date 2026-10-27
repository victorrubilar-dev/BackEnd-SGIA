<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class StockAlertController extends Controller
{
    /**
     * Display a listing of critical and warning stock alerts (REQ-06).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Producto::with(['equipos.estante.sala', 'carrera', 'categoria'])
            ->where(function ($q) {
                $q->whereColumn('stock_actual', '<=', 'stock_minimo')
                  ->orWhereRaw('stock_actual <= stock_minimo + 5');
            });

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
        $currentPage = max(1, $request->integer('page', 1));
        $total = $query->count();
        $products = $query->forPage($currentPage, $perPage)->get();

        $alerts = $products->map(function (Producto $p) {
            $isCritical = $p->stock_actual <= $p->stock_minimo;
            return [
                'id' => $p->id,
                'product_id' => $p->id,
                'product_name' => $p->nombre,
                'product_barcode' => $p->sku,
                'alert_type' => $isCritical ? 'critical' : 'warning',
                'current_stock' => (int) $p->stock_actual,
                'stock_minimo' => (int) $p->stock_minimo,
                'message' => $isCritical
                    ? "Stock crítico: el producto {$p->nombre} ha alcanzado su stock mínimo ({$p->stock_actual}/{$p->stock_minimo})."
                    : "Advertencia de stock: el producto {$p->nombre} está a 5 o menos unidades de su stock mínimo.",
                'is_resolved' => false,
                'created_at' => $p->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ];
        });

        $paginator = new LengthAwarePaginator(
            $alerts,
            $total,
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($paginator);
    }

    /**
     * Mark an alert as resolved.
     */
    public function resolve(int|string $id): JsonResponse
    {
        return response()->json([
            'message' => 'Alerta marcada como resuelta exitosamente.',
            'alert' => [
                'id' => (int) $id,
                'is_resolved' => true,
            ],
        ]);
    }
}
