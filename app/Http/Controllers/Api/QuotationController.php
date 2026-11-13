<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuotationResource;
use App\Models\Quotation;
use App\Services\QuotationStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Historial de cotizaciones del flujo antiguo (REQ-07), solo lectura desde el nuevo flujo de compras:
 * las escrituras se retiraron; las cotizaciones nuevas viven en la solicitud de compra.
 */
class QuotationController extends Controller
{
    /**
     * List quotations with filters (REQ-07).
     *
     * Query params: status (alias: estado), search (código de cotización u OC), per_page.
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        Gate::authorize('viewAny', Quotation::class);

        $query = Quotation::with(['items', 'suppliers.supplier', 'purchase']);

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
            $search = '%'.$request->string('search').'%';
            // Por código de la cotización o de la OC a la que se convirtió.
            $query->where(fn ($q) => $q->where('code', 'ilike', $search)
                ->orWhereHas('purchase', fn ($p) => $p->where('code', 'ilike', $search)));
        }

        $perPage = $this->perPage($request);
        $quotations = $query->orderByDesc('created_at')->paginate($perPage);

        return QuotationResource::collection($quotations);
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
}
