<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockAlertResource;
use App\Models\StockAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockAlertController extends Controller
{
    /**
     * Display a listing of critical and warning stock alerts (REQ-06).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
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

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
        $alerts = $query->orderByDesc('created_at')->paginate($perPage);

        return StockAlertResource::collection($alerts);
    }

    /**
     * Mark an alert as resolved.
     */
    public function resolve(StockAlert $stockAlert): JsonResponse
    {
        $stockAlert->forceFill(['is_resolved' => true])->save();

        return response()->json([
            'message' => 'Alerta marcada como resuelta exitosamente.',
            'alert' => new StockAlertResource($stockAlert->load('product')),
        ]);
    }
}
