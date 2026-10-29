<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidPurchaseStatusTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScanArrivalRequest;
use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\UpdatePurchaseStatusRequest;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Services\InvoiceOcrService;
use App\Services\PurchaseService;
use App\Services\PurchaseStateMachine;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseController extends Controller
{
    /**
     * List purchase orders with filters (REQ-08).
     *
     * Query params: status (alias: estado), supplier_id, search, per_page.
     */
    public function index(Request $request, PurchaseService $purchaseService): AnonymousResourceCollection|JsonResponse
    {
        Gate::authorize('viewAny', Purchase::class);

        $query = Purchase::with(['supplier', 'items']);

        $purchaseService->applyFilters($query, $request);

        $perPage = $this->perPage($request);
        $purchases = $query->orderByDesc('created_at')->paginate($perPage);

        return PurchaseResource::collection($purchases);
    }

    /**
     * Register a new purchase order in `pendiente` status (REQ-08).
     *
     * Puede crearse desde cero (proveedor + ítems) o a partir de una cotización
     * aceptada (REQ-07 -> REQ-08), en cuyo caso se copian proveedor e ítems y la
     * cotización queda marcada como `convertida`.
     */
    public function store(StorePurchaseRequest $request, QuotationService $quotationService, PurchaseService $purchaseService): JsonResponse
    {
        $purchase = $purchaseService->create($request->validated(), $request->user(), $quotationService);

        return (new PurchaseResource($purchase->load(['supplier', 'items', 'quotation'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified purchase order with its items.
     */
    public function show(Purchase $purchase): PurchaseResource
    {
        Gate::authorize('view', $purchase);

        return new PurchaseResource($purchase->load(['supplier', 'items', 'creator']));
    }

    /**
     * Advance the purchase status through the state machine (REQ-08).
     *
     * pendiente -> en_camino -> completa
     */
    public function updateStatus(
        UpdatePurchaseStatusRequest $request,
        Purchase $purchase,
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
            'purchase' => new PurchaseResource($purchase->load(['supplier', 'items'])),
        ]);
    }

    /**
     * Mark the purchase as `completa` by scanning the dispatch guide /
     * arrival invoice (REQ-08).
     *
     * On completion it notifies the warehouse keeper (PAN-01) to physically
     * receive the items into stock.
     */
    public function arrivalScan(
        ScanArrivalRequest $request,
        Purchase $purchase,
        PurchaseService $purchaseService,
        InvoiceOcrService $ocrService
    ): JsonResponse {
        $validated = $request->validated();
        $attributes = [];
        $invoiceNumber = $validated['invoice_number'] ?? null;

        if ($request->hasFile('document')) {
            $file = $request->file('document');

            $attributes['arrival_document'] = $file->store('purchases/arrival', 'local');

            if ($invoiceNumber === null) {
                // Extrae el número de factura desde el documento escaneado (OCR).
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
            'guide_number' => $purchase->guide_number,
            'invoice_number' => $purchase->invoice_number,
            'arrival_document' => $purchase->arrival_document,
            'received_at' => $purchase->received_at?->toIso8601String(),
            'purchase' => new PurchaseResource($purchase->load(['supplier', 'items'])),
        ]);
    }

    /**
     * Response for transitions that violate the state machine.
     *
     * @return JsonResponse<int, array<string, mixed>>
     */
    private function invalidTransitionResponse(
        InvalidPurchaseStatusTransition $e,
        Purchase $purchase
    ): JsonResponse {
        return response()->json([
            'message' => $e->getMessage(),
            'current_status' => $purchase->status,
            'attempted_status' => $e->to,
            'allowed_transitions' => PurchaseStateMachine::allowedFrom($purchase->status),
        ], 422);
    }

    /**
     * GET /api/purchases/{id}/arrival-document
     *
     * Descarga el documento de llegada desde el disco privado.
     */
    public function arrivalDocument(Purchase $purchase): StreamedResponse
    {
        Gate::authorize('view', $purchase);

        abort_unless(
            $purchase->arrival_document && Storage::disk('local')->exists($purchase->arrival_document),
            404,
            'La compra no tiene documento de llegada.'
        );

        return Storage::disk('local')->download($purchase->arrival_document);
    }
}
