<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidQuotationStatusTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuotationRequest;
use App\Http\Requests\StoreQuotationResponseRequest;
use App\Http\Requests\UpdateQuotationStatusRequest;
use App\Http\Resources\QuotationResource;
use App\Http\Resources\QuotationSupplierResource;
use App\Models\Quotation;
use App\Services\QuotationService;
use App\Services\QuotationStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class QuotationController extends Controller
{
    /**
     * List quotations with filters (REQ-07).
     *
     * Query params: status (alias: estado), search, per_page.
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        Gate::authorize('viewAny', Quotation::class);

        $query = Quotation::with(['items', 'suppliers.supplier']);

        $status = $request->input('status', $request->input('estado'));

        if ($status !== null && ! QuotationStateMachine::isValidStatus((string) $status)) {
            throw ValidationException::withMessages([
                'status' => 'El estado debe ser: pendiente, aceptada, rechazada o convertida.',
            ]);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->string('search') . '%';
            $query->where('code', 'ilike', $search);
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
        $quotations = $query->orderByDesc('created_at')->paginate($perPage);

        return QuotationResource::collection($quotations);
    }

    /**
     * Create a quotation and email it to at least 3 suppliers (REQ-07).
     */
    public function store(StoreQuotationRequest $request, QuotationService $quotationService): JsonResponse
    {
        $result = $quotationService->create($request->validated(), $request->user());

        return response()->json([
            'message' => 'Cotización creada y enviada a los proveedores contactados.',
            'emails_sent' => $result['emails_sent'],
            'quotation' => new QuotationResource($result['quotation']),
        ], 201);
    }

    /**
     * Display the quotation with items, contacted suppliers and responses.
     */
    public function show(Quotation $quotation): QuotationResource
    {
        Gate::authorize('view', $quotation);

        return new QuotationResource(
            $quotation->load(['items', 'suppliers.supplier', 'purchase', 'creator'])
        );
    }

    /**
     * Advance the quotation status through its state machine (REQ-07).
     */
    public function updateStatus(
        UpdateQuotationStatusRequest $request,
        Quotation $quotation,
        QuotationService $quotationService
    ): JsonResponse {
        $toStatus = $request->validated('status');

        try {
            $quotationService->transition($quotation, $toStatus);
        } catch (InvalidQuotationStatusTransition $e) {
            return $this->invalidTransitionResponse($e, $quotation);
        }

        return response()->json([
            'message' => "Estado de la cotización actualizado a \"{$quotation->status}\".",
            'allowed_transitions' => QuotationStateMachine::allowedFrom($quotation->status),
            'quotation' => new QuotationResource($quotation->fresh(['items', 'suppliers.supplier'])),
        ]);
    }

    /**
     * Record the response of a contacted supplier (REQ-07).
     *
     * If a supplier accepts, the quotation automatically moves to `aceptada`;
     * if every supplier rejects, it moves to `rechazada`.
     */
    public function storeResponse(
        StoreQuotationResponseRequest $request,
        Quotation $quotation,
        QuotationService $quotationService
    ): JsonResponse {
        $contact = $quotationService->recordResponse($quotation, $request->validated());

        return response()->json([
            'message' => 'Respuesta del proveedor registrada exitosamente.',
            'response' => new QuotationSupplierResource($contact->load('supplier')),
            'quotation' => new QuotationResource($quotation->fresh(['items', 'suppliers.supplier'])),
        ], 201);
    }

    /**
     * Response for transitions that violate the state machine.
     *
     * @return JsonResponse<int, array<string, mixed>>
     */
    private function invalidTransitionResponse(
        InvalidQuotationStatusTransition $e,
        Quotation $quotation
    ): JsonResponse {
        return response()->json([
            'message' => $e->getMessage(),
            'current_status' => $quotation->status,
            'attempted_status' => $e->to,
            'allowed_transitions' => QuotationStateMachine::allowedFrom($quotation->status),
        ], 422);
    }
}
