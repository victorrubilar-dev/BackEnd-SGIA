<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLoanRequest;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Services\LoanService;
use App\Services\LoanStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
}
