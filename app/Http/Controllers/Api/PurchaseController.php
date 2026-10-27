<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidPurchaseStatusTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScanArrivalRequest;
use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\UpdatePurchaseStatusRequest;
use App\Http\Resources\PurchaseResource;
use App\Models\Carrera;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\ProcesoCotizacion;
use App\Models\Producto;
use App\Services\InvoiceOcrService;
use App\Services\PurchaseService;
use App\Services\PurchaseStateMachine;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    /**
     * List purchase orders with filters (REQ-08).
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        Gate::authorize('viewAny', ProcesoCotizacion::class);

        $query = ProcesoCotizacion::with(['cotizaciones.proveedor', 'cotizaciones.items.producto', 'usuario']);

        $status = $request->input('status', $request->input('estado'));

        if ($status !== null && ! PurchaseStateMachine::isValidStatus((string) $status)) {
            throw ValidationException::withMessages([
                'status' => 'El estado debe ser: pendiente, en_camino o completa.',
            ]);
        }

        if ($status !== null) {
            $query->whereRaw('LOWER(estado) = ?', [strtolower($status)]);
        }

        if ($request->filled('supplier_id')) {
            $query->whereHas('cotizaciones', fn ($q) => $q->where('proveedor_id', $request->integer('supplier_id')));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('titulo_proceso', 'ilike', $search)
                  ->orWhere('id', 'ilike', $search);
            });
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
        $purchases = $query->orderByDesc('id')->paginate($perPage);

        return PurchaseResource::collection($purchases);
    }

    /**
     * Register a new purchase order in `pendiente` status (REQ-08).
     */
    public function store(StorePurchaseRequest $request, QuotationService $quotationService): JsonResponse
    {
        $validated = $request->validated();
        $quotation = null;

        if (! empty($validated['quotation_id'])) {
            $quotation = ProcesoCotizacion::with('cotizaciones.items')->findOrFail($validated['quotation_id']);

            if ($quotation->status !== 'aceptada') {
                throw ValidationException::withMessages([
                    'quotation_id' => 'Solo se puede generar una orden de compra desde una cotización aceptada '
                        . "(estado actual: \"{$quotation->status}\").",
                ]);
            }

            if (isset($validated['supplier_id']) && ! $quotationService->wasContacted($quotation, (int) $validated['supplier_id'])) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'El proveedor debe estar entre los contactados en la cotización.',
                ]);
            }
        }

        $supplierId = $validated['supplier_id']
            ?? ($quotation ? $quotationService->preferredSupplierId($quotation) : null);

        $items = $validated['items'] ?? ($quotation ? $quotation->items->map(fn ($it) => [
            'product_id' => $it->producto_id,
            'name' => $it->name,
            'quantity' => $it->quantity,
            'unit_price' => $it->unit_price,
        ])->all() : []);

        if (empty($items)) {
            throw ValidationException::withMessages([
                'items' => 'La orden de compra debe incluir al menos un producto.',
            ]);
        }

        if ($supplierId === null) {
            throw ValidationException::withMessages([
                'supplier_id' => 'La cotización no tiene proveedores contactados para asignar a la orden de compra.',
            ]);
        }

        $purchase = DB::transaction(function () use ($validated, $request, $quotation, $quotationService, $items, $supplierId) {
            $itemsCollect = collect($items);

            $total = $itemsCollect->contains(fn (array $item) => ! isset($item['unit_price']))
                ? null
                : $itemsCollect->sum(fn (array $item) => $item['unit_price'] * $item['quantity']);

            $carreraId = $request->user()?->carreras()->first()?->id ?? Carrera::first()?->id ?? 1;
            $titulo = $validated['notes'] ?? ('Orden de Compra ' . date('d/m/Y H:i'));

            $purchase = ProcesoCotizacion::create([
                'usuario_id' => $request->user()->id,
                'carrera_id' => $carreraId,
                'titulo_proceso' => $titulo,
                'estado' => PurchaseStateMachine::STATUS_PENDING,
            ]);

            $cotizacion = Cotizacion::create([
                'proceso_cotizacion_id' => $purchase->id,
                'proveedor_id' => $supplierId,
                'numero_cotizacion' => $validated['quotation_reference'] ?? $quotation?->code,
                'monto_total' => $total,
                'estado' => 'adjudicada',
                'fecha_envio' => now(),
            ]);

            foreach ($itemsCollect as $item) {
                CotizacionItem::create([
                    'cotizacion_id' => $cotizacion->id,
                    'producto_id' => $item['product_id'] ?? Producto::first()?->id ?? 1,
                    'cantidad_solicitada' => $item['quantity'],
                    'precio_unitario_ofertado' => $item['unit_price'] ?? null,
                ]);
            }

            if ($quotation) {
                $quotationService->markAsConverted($quotation, $purchase);
            }

            return $purchase;
        });

        return (new PurchaseResource($purchase->load(['cotizaciones.proveedor', 'cotizaciones.items.producto'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified purchase order with its items.
     */
    public function show(ProcesoCotizacion $purchase): PurchaseResource
    {
        Gate::authorize('view', $purchase);

        return new PurchaseResource($purchase->load(['cotizaciones.proveedor', 'cotizaciones.items.producto', 'usuario']));
    }

    /**
     * Advance the purchase status through the state machine (REQ-08).
     */
    public function updateStatus(
        UpdatePurchaseStatusRequest $request,
        ProcesoCotizacion $purchase,
        PurchaseService $purchaseService
    ): JsonResponse {
        $toStatus = $request->validated('status');

        try {
            $purchaseService->transition($purchase, $toStatus);
        } catch (InvalidPurchaseStatusTransition $e) {
            return $this->invalidTransitionResponse($e, $purchase);
        }

        return response()->json([
            'message' => "Estado de la orden de compra actualizado a \"{$purchase->status}\".",
            'allowed_transitions' => PurchaseStateMachine::allowedFrom($purchase->status),
            'purchase' => new PurchaseResource($purchase->load(['cotizaciones.proveedor', 'cotizaciones.items.producto'])),
        ]);
    }

    /**
     * Mark the purchase as `completa` by scanning the dispatch guide.
     */
    public function arrivalScan(
        ScanArrivalRequest $request,
        ProcesoCotizacion $purchase,
        PurchaseService $purchaseService,
        InvoiceOcrService $ocrService
    ): JsonResponse {
        $validated = $request->validated();
        $attributes = [];
        $invoiceNumber = $validated['invoice_number'] ?? null;

        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $attributes['arrival_document'] = $file->store('purchases/arrival', 'public');

            if ($invoiceNumber === null) {
                $invoiceNumber = $ocrService->extractProductsFromInvoice($file)['invoice_number'];
            }
        }

        if ($invoiceNumber !== null) {
            $attributes['invoice_number'] = $invoiceNumber;
        }

        if (! empty($validated['guide_number'])) {
            $attributes['guide_number'] = $validated['guide_number'];
        }

        try {
            $purchaseService->completeFromArrival($purchase, $attributes);
        } catch (InvalidPurchaseStatusTransition $e) {
            return $this->invalidTransitionResponse($e, $purchase);
        }

        return response()->json([
            'message' => "Orden de compra {$purchase->code} completada. El pañolero fue notificado para el ingreso físico a stock.",
            'guide_number' => $attributes['guide_number'] ?? null,
            'invoice_number' => $attributes['invoice_number'] ?? null,
            'arrival_document' => $attributes['arrival_document'] ?? null,
            'received_at' => now()->toIso8601String(),
            'purchase' => new PurchaseResource($purchase->load(['cotizaciones.proveedor', 'cotizaciones.items.producto'])),
        ]);
    }

    /**
     * Response for transitions that violate the state machine.
     */
    private function invalidTransitionResponse(
        InvalidPurchaseStatusTransition $e,
        ProcesoCotizacion $purchase
    ): JsonResponse {
        return response()->json([
            'message' => $e->getMessage(),
            'current_status' => $purchase->status,
            'attempted_status' => $e->to,
            'allowed_transitions' => PurchaseStateMachine::allowedFrom($purchase->status),
        ], 422);
    }
}
