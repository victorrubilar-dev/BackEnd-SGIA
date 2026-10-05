<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidPurchaseStatusTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScanArrivalRequest;
use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\UpdatePurchaseStatusRequest;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\QuotationItem;
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
     *
     * Query params: status (alias: estado), supplier_id, search, per_page.
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        Gate::authorize('viewAny', Purchase::class);

        $query = Purchase::with(['supplier', 'items']);

        $status = $request->input('status', $request->input('estado'));

        if ($status !== null && ! PurchaseStateMachine::isValidStatus((string) $status)) {
            throw ValidationException::withMessages([
                'status' => 'El estado debe ser: pendiente, en_camino o completa.',
            ]);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('quotation_id')) {
            $query->where('quotation_id', $request->integer('quotation_id'));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ilike', $search)
                    ->orWhere('guide_number', 'ilike', $search)
                    ->orWhere('invoice_number', 'ilike', $search);
            });
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
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
    public function store(StorePurchaseRequest $request, QuotationService $quotationService): JsonResponse
    {
        $validated = $request->validated();
        $quotation = null;

        if (! empty($validated['quotation_id'])) {
            $quotation = Quotation::with('items')->findOrFail($validated['quotation_id']);

            if ($quotation->status !== Quotation::STATUS_ACCEPTED) {
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

        $items = $validated['items']
            ?? $quotation?->items->map(fn (QuotationItem $item) => [
                'product_id' => $item->product_id,
                'name' => $item->name,
                'quantity' => $item->quantity,
            ])->all();

        $supplierId = $validated['supplier_id']
            ?? ($quotation ? $quotationService->preferredSupplierId($quotation) : null);

        if ($quotation && empty($validated['items']) && $quotation->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'La cotización no tiene ítems para copiar en la orden de compra.',
            ]);
        }

        if ($quotation && $supplierId === null) {
            throw ValidationException::withMessages([
                'supplier_id' => 'La cotización no tiene proveedores contactados para asignar a la orden de compra.',
            ]);
        }

        $purchase = DB::transaction(function () use ($validated, $request, $quotation, $quotationService, $items, $supplierId) {
            $items = collect($items);

            $total = $items->contains(fn (array $item) => ! isset($item['unit_price']))
                ? null
                : $items->sum(fn (array $item) => $item['unit_price'] * $item['quantity']);

            $purchase = Purchase::create([
                'code' => Purchase::generateUniqueCode(),
                'supplier_id' => $supplierId,
                'created_by' => $request->user()->id,
                'quotation_id' => $quotation?->id,
                'status' => PurchaseStateMachine::STATUS_PENDING,
                'quotation_reference' => $validated['quotation_reference'] ?? $quotation?->code,
                'expected_at' => $validated['expected_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'total' => $total,
            ]);

            foreach ($items as $item) {
                $purchase->items()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'] ?? null,
                ]);
            }

            if ($quotation) {
                // La cotización aprobada queda vinculada y convertida en OC.
                $quotationService->markAsConverted($quotation, $purchase);
            }

            return $purchase;
        });

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

            $attributes['arrival_document'] = $file->store('purchases/arrival', 'public');

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
}
