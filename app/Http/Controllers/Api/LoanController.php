<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidLoanStatusTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectLoanRequest;
use App\Http\Requests\StoreLoanCheckoutRequest;
use App\Http\Requests\StoreLoanRequest;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Services\LoanService;
use App\Services\LoanStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LoanController extends Controller
{
    /**
     * Registra una solicitud remota de préstamo del docente (REQ-09).
     *
     * Body: items (product_id + quantity), subject, room, loan_date.
     * La solicitud nace en estado `pendiente` hasta que el pañol la procese.
     */
    public function store(StoreLoanRequest $request, LoanService $loanService): JsonResponse
    {
        $loan = $loanService->createRequest($request->validated(), $request->user());

        $data = (new LoanResource($loan->load('items')))->resolve();

        return response()->json([
            'message' => 'Solicitud enviada correctamente.',
            'loan' => $data,
            'data' => $data,
        ], 201);
    }

    /**
     * Lista las solicitudes propias del docente autenticado (REQ-09).
     *
     * Query params: estado (alias: status), per_page.
     */
    public function myRequests(Request $request): AnonymousResourceCollection
    {
        $query = Loan::where('requested_by', $request->user()->id)
            ->with('items.product')
            ->orderByDesc('created_at');

        $status = $request->input('estado', $request->input('status'));

        if ($status !== null && ! LoanStateMachine::isValidStatus((string) $status)) {
            throw ValidationException::withMessages([
                'estado' => 'El estado debe ser: pendiente, en_proceso, procesado o rechazado.',
            ]);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));

        return LoanResource::collection($query->paginate($perPage));
    }

    /**
     * Solicitudes remotas pendientes de despachar en el pañol (REQ-10).
     *
     * Cada ítem incluye la cantidad solicitada, el stock disponible y la
     * ubicación física (sala / cajón) del producto.
     */
    public function pending(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('pending', Loan::class);

        $query = Loan::where('status', LoanStateMachine::STATUS_PENDING)
            ->where('type', Loan::TYPE_REMOTE)
            ->with(['items.product.location', 'requester'])
            ->orderBy('created_at');

        $perPage = max(1, min(100, $request->integer('per_page', 15)));

        return LoanResource::collection($query->paginate($perPage));
    }

    /**
     * Aprueba la solicitud: descuenta el stock y notifica al docente (REQ-10).
     */
    public function approve(Request $request, Loan $loan, LoanService $loanService): JsonResponse
    {
        Gate::authorize('approve', $loan);

        try {
            $loanService->approve($loan, $request->user());
        } catch (InvalidLoanStatusTransition $e) {
            return $this->invalidTransitionResponse($e, $loan);
        }

        return response()->json([
            'message' => "Préstamo {$loan->code} aprobado: stock descontado y docente notificado.",
            'allowed_transitions' => LoanStateMachine::allowedFrom($loan->status),
            'loan' => new LoanResource($loan->fresh(['items.product'])),
        ]);
    }

    /**
     * Rechaza la solicitud indicando el motivo y notifica al docente (REQ-10).
     */
    public function reject(RejectLoanRequest $request, Loan $loan, LoanService $loanService): JsonResponse
    {
        try {
            $loanService->reject($loan, $request->user(), $request->validated('rejection_reason'));
        } catch (InvalidLoanStatusTransition $e) {
            return $this->invalidTransitionResponse($e, $loan);
        }

        return response()->json([
            'message' => "Préstamo {$loan->code} rechazado: el docente fue notificado con el motivo.",
            'allowed_transitions' => LoanStateMachine::allowedFrom($loan->status),
            'loan' => new LoanResource($loan),
        ]);
    }

    /**
     * Confirma la entrega material al docente: `en_proceso` -> `procesado`
     * y notifica al solicitante (REQ-10).
     */
    public function deliver(Request $request, Loan $loan, LoanService $loanService): JsonResponse
    {
        Gate::authorize('deliver', $loan);

        try {
            $loanService->deliver($loan, $request->user());
        } catch (InvalidLoanStatusTransition $e) {
            return $this->invalidTransitionResponse($e, $loan);
        }

        return response()->json([
            'message' => "Préstamo {$loan->code} entregado y marcado como procesado.",
            'allowed_transitions' => LoanStateMachine::allowedFrom($loan->status),
            'loan' => new LoanResource($loan),
        ]);
    }

    /**
     * Registra un préstamo presencial directo (profesor/estudiante, insumo,
     * cantidad, asignatura, sala y fecha): descuenta stock y nace `procesado`
     * (REQ-10).
     */
    public function storeCheckout(StoreLoanCheckoutRequest $request, LoanService $loanService): JsonResponse
    {
        $loan = $loanService->checkout($request->validated(), $request->user());

        $data = (new LoanResource($loan->load(['items.product'])))->resolve();

        return response()->json([
            'message' => 'Préstamo presencial registrado y stock descontado.',
            'loan' => $data,
            'data' => $data,
        ], 201);
    }

    /**
     * Response for transitions that violate the state machine.
     *
     * @return JsonResponse<int, array<string, mixed>>
     */
    private function invalidTransitionResponse(
        InvalidLoanStatusTransition $e,
        Loan $loan
    ): JsonResponse {
        return response()->json([
            'message' => $e->getMessage(),
            'current_status' => $loan->status,
            'attempted_status' => $e->to,
            'allowed_transitions' => LoanStateMachine::allowedFrom($loan->status),
        ], 422);
    }
}
