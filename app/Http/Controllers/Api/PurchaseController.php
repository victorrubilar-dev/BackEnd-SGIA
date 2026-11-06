<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidPurchaseStatusTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScanArrivalRequest;
use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\UpdatePurchaseStatusRequest;
use App\Http\Resources\BudgetMovementResource;
use App\Http\Resources\InvoiceIntakeResource;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\QuotationResource;
use App\Models\AnnualBudget;
use App\Models\BudgetMovement;
use App\Models\InvoiceIntake;
use App\Models\Purchase;
use App\Models\Quotation;
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

        if ($request->boolean('linkable')) {
            $query->linkable();
        }

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
        Gate::authorize('useBudget', [Purchase::class, AnnualBudget::findOrFail($request->integer('annual_budget_id'))]);

        $purchase = $purchaseService->create($request->validated(), $request->user(), $quotationService);

        return (new PurchaseResource($purchase->load(['supplier', 'items', 'quotation.suppliers'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified purchase order with its items.
     */
    public function show(Purchase $purchase): PurchaseResource
    {
        Gate::authorize('view', $purchase);

        return new PurchaseResource($purchase->load(['supplier', 'items', 'creator', 'quotation.suppliers']));
    }

    /**
     * Cadena OC → cotizaciones → facturas → ingreso de stock → movimientos de presupuesto (REQ-08).
     */
    public function trace(Purchase $purchase): JsonResponse
    {
        Gate::authorize('view', $purchase);

        $quotations = Quotation::where('purchase_id', $purchase->id)
            ->when($purchase->quotation_id, fn ($q, $id) => $q->orWhere('id', $id))
            ->get();
        $invoices = InvoiceIntake::with('items.product:id,name')->where('purchase_id', $purchase->id)->orderBy('id')->get();
        $stock = $invoices->where('status', InvoiceIntake::STATUS_CONFIRMED)->flatMap->items
            ->groupBy('product_id')
            ->map(fn ($rows) => ['product_id' => $rows->first()->product_id, 'name' => $rows->first()->product->name, 'quantity' => (int) $rows->sum('quantity')])
            ->values();

        return response()->json([
            'purchase' => new PurchaseResource($purchase->load(['supplier', 'items'])),
            'quotations' => QuotationResource::collection($quotations),
            'invoices' => InvoiceIntakeResource::collection($invoices),
            'budget_movements' => BudgetMovementResource::collection(BudgetMovement::with('user')->where(fn ($q) => $q->where('purchase_id', $purchase->id)->orWhereIn('invoice_intake_id', $invoices->modelKeys()))->orderBy('id')->get()),
            'stock_entries' => $stock,
        ]);
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
     * Cancela la compra (`pendiente` o `en_camino`) y libera su reserva de presupuesto.
     */
    public function cancel(Request $request, Purchase $purchase, PurchaseService $purchaseService): JsonResponse|PurchaseResource
    {
        Gate::authorize('cancel', $purchase);

        try {
            $purchaseService->cancel($purchase, $request->user());
        } catch (InvalidPurchaseStatusTransition $e) {
            return $this->invalidTransitionResponse($e, $purchase);
        }

        return new PurchaseResource($purchase->refresh()->load(['supplier', 'items']));
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
                // Mejor esfuerzo: si el OCR falla, la recepción igual se completa.
                try {
                    $invoiceNumber = $ocrService->extractProductsFromInvoice($file)['invoice_number'];
                } catch (\RuntimeException $e) {
                    report($e);
                }
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
        return $this->unprocessable($e->getMessage(), 'status', [
            'current_status' => $purchase->status,
            'attempted_status' => $e->to,
            'allowed_transitions' => PurchaseStateMachine::allowedFrom($purchase->status),
        ]);
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
