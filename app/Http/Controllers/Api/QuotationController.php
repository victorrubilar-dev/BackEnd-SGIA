<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidQuotationStatusTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuotationRequest;
use App\Http\Requests\StoreQuotationResponseRequest;
use App\Http\Requests\UpdateQuotationStatusRequest;
use App\Http\Resources\QuotationResource;
use App\Http\Resources\QuotationSupplierResource;
use App\Models\ProcesoCotizacion;
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
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        Gate::authorize('viewAny', ProcesoCotizacion::class);

        $query = ProcesoCotizacion::with(['cotizaciones.proveedor', 'cotizaciones.items.producto', 'usuario']);

        $status = $request->input('status', $request->input('estado'));

        if ($status !== null && ! QuotationStateMachine::isValidStatus((string) $status)) {
            throw ValidationException::withMessages([
                'status' => 'El estado debe ser: pendiente, aceptada, rechazada o convertida.',
            ]);
        }

        if ($status !== null) {
            $query->whereRaw('LOWER(estado) = ?', [strtolower($status)]);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('titulo_proceso', 'ilike', $search)
                  ->orWhere('id', 'ilike', $search);
            });
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
        $quotations = $query->orderByDesc('id')->paginate($perPage);

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
    public function show(ProcesoCotizacion $quotation): QuotationResource
    {
        Gate::authorize('view', $quotation);

        return new QuotationResource(
            $quotation->load(['cotizaciones.proveedor', 'cotizaciones.items.producto', 'usuario'])
        );
    }

    /**
     * Advance the quotation status through its state machine (REQ-07).
     */
    public function updateStatus(
        UpdateQuotationStatusRequest $request,
        ProcesoCotizacion $quotation,
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
            'quotation' => new QuotationResource($quotation->fresh(['cotizaciones.proveedor', 'cotizaciones.items.producto'])),
        ]);
    }

    /**
     * Record the response of a contacted supplier (REQ-07).
     */
    public function storeResponse(
        StoreQuotationResponseRequest $request,
        ProcesoCotizacion $quotation,
        QuotationService $quotationService
    ): JsonResponse {
        $contact = $quotationService->recordResponse($quotation, $request->validated());

        return response()->json([
            'message' => 'Respuesta del proveedor registrada exitosamente.',
            'response' => new QuotationSupplierResource($contact->load('proveedor')),
            'quotation' => new QuotationResource($quotation->fresh(['cotizaciones.proveedor', 'cotizaciones.items.producto'])),
        ], 201);
    }

    /**
     * Response for transitions that violate the state machine.
     */
    private function invalidTransitionResponse(
        InvalidQuotationStatusTransition $e,
        ProcesoCotizacion $quotation
    ): JsonResponse {
        return response()->json([
            'message' => $e->getMessage(),
            'current_status' => $quotation->status,
            'attempted_status' => $e->to,
            'allowed_transitions' => QuotationStateMachine::allowedFrom($quotation->status),
        ], 422);
    }
}
