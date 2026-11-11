<?php

namespace App\Http\Controllers\Api;

use App\Enums\SupplierArea;
use App\Http\Controllers\Controller;
use App\Http\Requests\SelectPurchaseRequestQuotationRequest;
use App\Http\Requests\SendPurchaseRequestRequest;
use App\Http\Requests\StorePurchaseRequestOrderRequest;
use App\Http\Requests\StorePurchaseRequestQuotationRequest;
use App\Http\Requests\StorePurchaseRequestRequest;
use App\Http\Requests\UpdatePurchaseRequestRequest;
use App\Http\Resources\PurchaseRequestQuotationResource;
use App\Http\Resources\PurchaseRequestResource;
use App\Models\AnnualBudget;
use App\Models\Purchase;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestQuotation;
use App\Services\PurchaseRequestService;
use App\Services\PurchaseRequestStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Solicitudes de compra (nuevo flujo de compras, `NUEVO-FLUJO-COMPRAS.md` §5).
 */
class PurchaseRequestController extends Controller
{
    /** Relaciones del detalle: ítems, áreas, contactados, cotizaciones, OC y su factura. */
    private const DETAIL = [
        'items.product', 'areas', 'suppliers.supplier', 'quotations.supplier', 'quotations.items',
        'purchase.supplier', 'purchase.items', 'purchase.invoiceIntakes', 'annualBudget', 'creator', 'approver',
    ];

    /**
     * Query params: status, code, from, to (fecha de creación), per_page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PurchaseRequest::class);

        $request->validate([
            'status' => ['sometimes', 'string', Rule::in(PurchaseRequestStateMachine::STATUSES)],
            'code' => ['sometimes', 'string', 'max:20'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ], ['status.in' => 'El estado debe ser: '.implode(', ', PurchaseRequestStateMachine::STATUSES).'.']);

        $query = PurchaseRequest::query()->with(['items.product', 'annualBudget', 'creator', 'purchase'])->withCount('quotations');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('code')) {
            $query->where('code', 'ilike', '%'.$request->string('code').'%');
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        return PurchaseRequestResource::collection($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    /** Enum de áreas para selects: `[{value, label}]`. */
    public function areas(): JsonResponse
    {
        Gate::authorize('viewAny', PurchaseRequest::class);

        return response()->json([
            'data' => array_map(fn (SupplierArea $a) => ['value' => $a->value, 'label' => $a->label()], SupplierArea::cases()),
        ]);
    }

    public function store(StorePurchaseRequestRequest $request, PurchaseRequestService $service): JsonResponse
    {
        Gate::authorize('useBudget', [Purchase::class, AnnualBudget::findOrFail($request->integer('annual_budget_id'))]);

        $purchaseRequest = $service->create($request->validated(), $request->user());

        return $this->detail($purchaseRequest)->response()->setStatusCode(201);
    }

    public function show(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        Gate::authorize('view', $purchaseRequest);

        return $this->detail($purchaseRequest);
    }

    public function update(UpdatePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service): PurchaseRequestResource
    {
        return $this->detail($service->update($purchaseRequest, $request->validated(), $request->user()));
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service): PurchaseRequestResource
    {
        Gate::authorize('manage', $purchaseRequest);

        return $this->detail($service->approve($purchaseRequest, $request->user()));
    }

    public function send(SendPurchaseRequestRequest $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service): JsonResponse
    {
        return $this->emailResponse($service->send($purchaseRequest, $request->validated('areas'), $request->user()));
    }

    public function resend(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service): JsonResponse
    {
        Gate::authorize('manage', $purchaseRequest);

        return $this->emailResponse($service->resend($purchaseRequest));
    }

    public function storeQuotation(StorePurchaseRequestQuotationRequest $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service): JsonResponse
    {
        $quotation = $service->addQuotation($purchaseRequest, $request->validated(), $request->file('file'), $request->user());

        return response()->json([
            'quotation' => new PurchaseRequestQuotationResource($quotation->load(['supplier', 'items'])),
            'purchase_request' => $this->detail($purchaseRequest),
        ], 201);
    }

    public function quotationFile(PurchaseRequest $purchaseRequest, PurchaseRequestQuotation $quotation): StreamedResponse
    {
        Gate::authorize('view', $purchaseRequest);

        abort_unless(Storage::disk('local')->exists($quotation->file_path), 404, 'La cotización no tiene archivo.');

        return Storage::disk('local')->download($quotation->file_path, $quotation->original_name ?: null);
    }

    public function destroyQuotation(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestQuotation $quotation, PurchaseRequestService $service): Response
    {
        Gate::authorize('manage', $purchaseRequest);

        $service->removeQuotation($purchaseRequest, $quotation, $request->user());

        return response()->noContent();
    }

    public function selectQuotation(SelectPurchaseRequestQuotationRequest $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service): PurchaseRequestResource
    {
        return $this->detail($service->selectQuotation($purchaseRequest, $request->integer('quotation_id'), $request->user()));
    }

    public function storeOrder(StorePurchaseRequestOrderRequest $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service): JsonResponse
    {
        Gate::authorize('useBudget', [Purchase::class, AnnualBudget::findOrFail($purchaseRequest->annual_budget_id)]);

        $service->issueOrder($purchaseRequest, $request->validated('order_number'), $request->file('file'), $request->user());

        return $this->detail($purchaseRequest)->response()->setStatusCode(201);
    }

    public function orderFile(PurchaseRequest $purchaseRequest): StreamedResponse
    {
        Gate::authorize('view', $purchaseRequest);

        $path = $purchaseRequest->purchases()->whereNotNull('order_file_path')->latest('id')->value('order_file_path');

        abort_unless($path && Storage::disk('local')->exists($path), 404, 'La solicitud no tiene orden de compra.');

        return Storage::disk('local')->download($path);
    }

    public function cancel(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service): PurchaseRequestResource
    {
        Gate::authorize('manage', $purchaseRequest);

        return $this->detail($service->cancel($purchaseRequest, $request->user()));
    }

    private function detail(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return new PurchaseRequestResource($purchaseRequest->refresh()->load(self::DETAIL)->loadCount('quotations'));
    }

    /**
     * @param  array{purchase_request: PurchaseRequest, emails_sent: int, emails_failed: int}  $result
     */
    private function emailResponse(array $result): JsonResponse
    {
        $failed = $result['emails_failed'];

        return response()->json([
            'message' => $failed === 0
                ? "Solicitud enviada a los proveedores ({$result['emails_sent']} correo(s))."
                : "Se enviaron {$result['emails_sent']} correo(s); {$failed} fallaron: revisa los proveedores y reintenta.",
            'emails_sent' => $result['emails_sent'],
            'emails_failed' => $failed,
            'purchase_request' => $this->detail($result['purchase_request']),
        ]);
    }
}
