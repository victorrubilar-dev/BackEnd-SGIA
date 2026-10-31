<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidLoanStatusTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeliverLoanRequest;
use App\Http\Requests\RejectLoanRequest;
use App\Http\Requests\StoreLoanCheckinRequest;
use App\Http\Requests\StoreLoanCheckoutRequest;
use App\Http\Requests\StoreLoanRequest;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Services\EquipmentPdfService;
use App\Services\LoanService;
use App\Services\LoanStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
            ->with(['items.product', 'items.units'])
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

        $perPage = $this->perPage($request);

        return LoanResource::collection($query->paginate($perPage));
    }

    /**
     * Historial de préstamos con filtros (REQ-11).
     *
     * Query params: estado (alias status), insumo (alias product / product_id),
     * profesor (alias teacher / borrower), sala (alias room), tipo (alias type),
     * search (código o asignatura), per_page.
     *
     * Cada préstamo incluye el campo explícito `status` más los booleanos
     * `is_processed` / `is_in_progress` para que el cliente diferencie
     * visualmente los préstamos procesados de los que siguen en proceso.
     */
    public function index(Request $request, LoanService $loanService): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Loan::class);

        $query = Loan::with(['items.product.location', 'items.units', 'requester'])
            ->orderByDesc('created_at');

        $loanService->applyHistoryFilters($query, $request);

        $perPage = $this->perPage($request);

        return LoanResource::collection($query->paginate($perPage));
    }

    /**
     * Detalle de un préstamo: ítems con stock y ubicación, personas
     * involucradas y transiciones permitidas (REQ-11).
     */
    public function show(Loan $loan): LoanResource
    {
        Gate::authorize('view', $loan);

        return new LoanResource(
            $loan->load(['items.product.location', 'items.units', 'requester', 'approver', 'processor'])
        );
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
            ->with(['items.product.location', 'items.units', 'requester'])
            ->orderBy('created_at');

        $perPage = $this->perPage($request);

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
            'loan' => new LoanResource($loan->fresh(['items.product', 'items.units'])),
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
    public function deliver(DeliverLoanRequest $request, Loan $loan, LoanService $loanService): JsonResponse
    {
        try {
            $loanService->deliver($loan, $request->user(), $request->validated('items', []));
        } catch (InvalidLoanStatusTransition $e) {
            return $this->invalidTransitionResponse($e, $loan);
        }

        return response()->json([
            'message' => "Préstamo {$loan->code} entregado y marcado como procesado.",
            'allowed_transitions' => LoanStateMachine::allowedFrom($loan->status),
            'loan' => new LoanResource($loan->load('items.units')),
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

        $data = (new LoanResource($loan->load(['items.product', 'items.units'])))->resolve();

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

    /**
     * Registra la devolución de ítems de un préstamo entregado (REQ-10).
     *
     * POST /api/loans/checkin
     */
    public function checkin(StoreLoanCheckinRequest $request, LoanService $loanService): JsonResponse
    {
        $loan = $loanService->checkin($request->validated(), $request->user());

        $message = $loan->returned_at !== null
            ? "Préstamo {$loan->code} devuelto por completo."
            : "Devolución parcial registrada en el préstamo {$loan->code}.";

        return response()->json([
            'message' => $message,
            'data' => (new LoanResource($loan))->resolve(),
        ]);
    }

    /**
     * El docente cancela una solicitud propia que sigue pendiente (REQ-09).
     *
     * DELETE /api/loans/requests/{loan}
     */
    public function cancel(Loan $loan, LoanService $loanService): Response
    {
        Gate::authorize('cancel', $loan);

        $loanService->cancelRequest($loan);

        return response()->noContent();
    }

    /**
     * Exporta el historial de préstamos en CSV (abre en Excel) o PDF.
     *
     * GET /api/loans/export?format=csv|pdf&from=YYYY-MM-DD&to=YYYY-MM-DD
     * más los mismos filtros de GET /api/loans.
     */
    public function export(Request $request, LoanService $loanService, EquipmentPdfService $pdfService): SymfonyResponse
    {
        Gate::authorize('viewAny', Loan::class);

        $validated = $request->validate([
            'format' => ['nullable', 'in:csv,pdf,excel'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = Loan::with(['items.units', 'requester'])->orderBy('loan_date')->orderBy('id');
        $loanService->applyHistoryFilters($query, $request);

        if (! empty($validated['from'])) {
            $query->whereDate('loan_date', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('loan_date', '<=', $validated['to']);
        }

        // Tope de seguridad: un export no debe cargar la tabla completa en memoria.
        $loans = $query->limit(5000)->get();
        $stamp = now()->format('Ymd-His');

        if (($validated['format'] ?? 'csv') === 'pdf') {
            return $pdfService->download(
                $pdfService->loansReport($loans, $validated['from'] ?? null, $validated['to'] ?? null),
                "prestamos-{$stamp}.pdf"
            );
        }

        return response()->streamDownload(function () use ($loans) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel lea UTF-8
            fputcsv($out, ['Código', 'Tipo', 'Estado', 'Solicitante', 'Asignatura', 'Sala', 'Fecha', 'Vencimiento', 'Devuelto', 'Atrasado', 'Ítems']);

            foreach ($loans as $loan) {
                fputcsv($out, [
                    $loan->code,
                    $loan->type,
                    $loan->status,
                    $loan->borrower_name ?? $loan->requester?->name,
                    $loan->subject,
                    $loan->room,
                    $loan->loan_date?->toDateString(),
                    ($loan->due_date ?? $loan->loan_date)?->toDateString(),
                    $loan->returned_at?->toDateTimeString(),
                    $loan->isOverdue() ? 'sí' : 'no',
                    $loan->items->map(fn ($i) => "{$i->name} x{$i->quantity}".($i->units->isNotEmpty() ? ' ['.$i->units->pluck('code')->implode(', ').']' : ''))->implode('; '),
                ]);
            }

            fclose($out);
        }, "prestamos-{$stamp}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
