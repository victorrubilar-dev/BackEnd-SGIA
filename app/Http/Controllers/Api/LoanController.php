<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidLoanStatusTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectLoanRequest;
use App\Http\Requests\StoreLoanCheckoutRequest;
use App\Http\Requests\StoreLoanRequest;
use App\Http\Resources\LoanResource;
use App\Models\Prestamo;
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
     */
    public function store(StoreLoanRequest $request, LoanService $loanService): JsonResponse
    {
        $loan = $loanService->createRequest($request->validated(), $request->user());

        $data = (new LoanResource($loan->load(['items.equipo.producto'])))->resolve();

        return response()->json([
            'message' => 'Solicitud enviada correctamente.',
            'loan' => $data,
            'data' => $data,
        ], 201);
    }

    /**
     * Lista las solicitudes propias del docente autenticado (REQ-09).
     */
    public function myRequests(Request $request): AnonymousResourceCollection
    {
        $query = Prestamo::where('profesor_id', $request->user()->id)
            ->with(['items.equipo.producto'])
            ->orderByDesc('id');

        $status = $request->input('estado', $request->input('status'));

        if ($status !== null && ! LoanStateMachine::isValidStatus((string) $status)) {
            throw ValidationException::withMessages([
                'estado' => 'El estado debe ser: pendiente, en_proceso, procesado o rechazado.',
            ]);
        }

        if ($status !== null) {
            $query->whereRaw('LOWER(estado) = ?', [strtolower($status)]);
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));

        return LoanResource::collection($query->paginate($perPage));
    }

    /**
     * Historial de préstamos con filtros (REQ-11).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Prestamo::class);

        $query = Prestamo::with(['items.equipo.producto.equipos.estante.sala', 'profesor', 'despachadoPor', 'recibidoPor'])
            ->orderByDesc('id');

        $status = $request->input('estado', $request->input('status'));

        if ($status !== null && ! LoanStateMachine::isValidStatus((string) $status)) {
            throw ValidationException::withMessages([
                'estado' => 'El estado debe ser: pendiente, en_proceso, procesado o rechazado.',
            ]);
        }

        if ($status !== null) {
            $query->whereRaw('LOWER(estado) = ?', [strtolower($status)]);
        }

        $type = $request->input('tipo', $request->input('type'));

        if ($type !== null && ! in_array($type, [Prestamo::TYPE_REMOTE, Prestamo::TYPE_DIRECT], true)) {
            throw ValidationException::withMessages([
                'tipo' => 'El tipo debe ser: remoto o presencial.',
            ]);
        }

        if ($type !== null) {
            $query->whereRaw('LOWER(tipo_prestamo) = ?', [strtolower($type)]);
        }

        $room = $request->input('sala') ?? $request->input('room');

        if ($room !== null && $room !== '') {
            $query->where('observaciones', 'ilike', '%' . $room . '%');
        }

        $teacher = $request->input('profesor')
            ?? $request->input('teacher')
            ?? $request->input('borrower');

        if ($teacher !== null && $teacher !== '') {
            $like = '%' . $teacher . '%';
            $query->where(function ($q) use ($like) {
                $q->where('observaciones', 'ilike', $like)
                    ->orWhereHas('profesor', fn ($qq) => $qq->where('nombre', 'ilike', $like));
            });
        }

        if ($request->filled('product_id')) {
            $productId = $request->integer('product_id');
            $query->whereHas('items.equipo', fn ($q) => $q->where('producto_id', $productId));
        } else {
            $product = $request->input('insumo') ?? $request->input('product');

            if ($product !== null && $product !== '') {
                $like = '%' . $product . '%';
                $query->whereHas('items.equipo.producto', fn ($q) => $q->where('nombre', 'ilike', $like));
            }
        }

        if ($request->filled('search')) {
            $like = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('id', 'ilike', $like)
                  ->orWhere('observaciones', 'ilike', $like);
            });
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));

        return LoanResource::collection($query->paginate($perPage));
    }

    /**
     * Detalle de un préstamo.
     */
    public function show(Prestamo $loan): LoanResource
    {
        Gate::authorize('view', $loan);

        return new LoanResource(
            $loan->load(['items.equipo.producto.equipos.estante.sala', 'profesor', 'despachadoPor', 'recibidoPor'])
        );
    }

    /**
     * Solicitudes remotas pendientes de despachar en el pañol (REQ-10).
     */
    public function pending(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('pending', Prestamo::class);

        $query = Prestamo::whereRaw('LOWER(estado) = ?', [LoanStateMachine::STATUS_PENDING])
            ->whereRaw('LOWER(tipo_prestamo) = ?', [Prestamo::TYPE_REMOTE])
            ->with(['items.equipo.producto.equipos.estante.sala', 'profesor'])
            ->orderBy('id');

        $perPage = max(1, min(100, $request->integer('per_page', 15)));

        return LoanResource::collection($query->paginate($perPage));
    }

    /**
     * Aprueba la solicitud: descuenta el stock y notifica al docente (REQ-10).
     */
    public function approve(Request $request, Prestamo $loan, LoanService $loanService): JsonResponse
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
            'loan' => new LoanResource($loan->fresh(['items.equipo.producto'])),
        ]);
    }

    /**
     * Rechaza la solicitud indicando el motivo y notifica al docente (REQ-10).
     */
    public function reject(RejectLoanRequest $request, Prestamo $loan, LoanService $loanService): JsonResponse
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
     * Confirma la entrega material al docente: `en_proceso` -> `procesado`.
     */
    public function deliver(Request $request, Prestamo $loan, LoanService $loanService): JsonResponse
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
     * Registra un préstamo presencial directo en el pañol.
     */
    public function storeCheckout(StoreLoanCheckoutRequest $request, LoanService $loanService): JsonResponse
    {
        $loan = $loanService->checkout($request->validated(), $request->user());

        $data = (new LoanResource($loan->load(['items.equipo.producto'])))->resolve();

        return response()->json([
            'message' => 'Préstamo presencial registrado exitosamente.',
            'loan' => $data,
            'data' => $data,
        ], 201);
    }

    /**
     * Respuesta homogénea cuando una transición no está permitida.
     */
    private function invalidTransitionResponse(InvalidLoanStatusTransition $e, Prestamo $loan): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'current_status' => $loan->status,
            'allowed_transitions' => LoanStateMachine::allowedFrom($loan->status),
        ], 422);
    }
}
