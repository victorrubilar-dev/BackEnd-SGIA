<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmInvoiceIntakeRequest;
use App\Http\Requests\StoreInvoiceIntakeRequest;
use App\Http\Resources\InvoiceIntakeResource;
use App\Models\AnnualBudget;
use App\Models\InvoiceIntake;
use App\Models\Purchase;
use App\Models\User;
use App\Services\InvoiceIntakeService;
use App\Services\PurchaseRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceIntakeController extends Controller
{
    /**
     * Query params: status, annual_budget_id, from, to, per_page. DIR-01 solo ve sus carreras.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', InvoiceIntake::class);

        $request->validate([
            'status' => ['sometimes', 'string', 'in:'.implode(',', InvoiceIntake::STATUSES)],
            'annual_budget_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = InvoiceIntake::query();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('annual_budget_id')) {
            $query->where('annual_budget_id', $request->integer('annual_budget_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('received_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('received_at', '<=', $request->date('to'));
        }

        return InvoiceIntakeResource::collection($query->with(['purchase:id,code,status', 'annualBudget'])->orderByDesc('received_at')->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(StoreInvoiceIntakeRequest $request, InvoiceIntakeService $service, PurchaseRequestService $purchaseRequests): JsonResponse
    {
        $budget = $request->filled('annual_budget_id') ? AnnualBudget::findOrFail($request->integer('annual_budget_id')) : null;
        Gate::authorize('create', [InvoiceIntake::class, $budget]);

        $purchaseId = $request->filled('purchase_id') ? $request->integer('purchase_id') : null;
        $purchase = $request->filled('purchase_request_code')
            ? $purchaseRequests->orderForInvoice($request->string('purchase_request_code')->toString(), $purchaseId)
            : ($purchaseId !== null ? Purchase::findOrFail($purchaseId) : null);

        // Por código de solicitud la OC ya se validó (`con_oc`); su reserva puede estar liberada por una factura anulada.
        $intake = $service->createDraft($request->file('file'), $budget, $request->user()->id, $purchase, requireReservation: ! $request->filled('purchase_request_code'));

        return (new InvoiceIntakeResource($intake->load(['items', 'purchase', 'annualBudget'])))->response()->setStatusCode(201);
    }

    public function show(InvoiceIntake $invoiceIntake): InvoiceIntakeResource
    {
        $invoiceIntake->load(['items.product.cajon.location', 'purchase', 'annualBudget']);
        Gate::authorize('view', $invoiceIntake);

        return new InvoiceIntakeResource($invoiceIntake);
    }

    public function file(InvoiceIntake $invoiceIntake): StreamedResponse
    {
        Gate::authorize('view', $invoiceIntake);

        abort_unless(
            $invoiceIntake->pdf_path && Storage::disk('local')->exists($invoiceIntake->pdf_path),
            404,
            'El ingreso no tiene archivo.'
        );

        return Storage::disk('local')->download($invoiceIntake->pdf_path);
    }

    public function confirm(ConfirmInvoiceIntakeRequest $request, InvoiceIntake $invoiceIntake, InvoiceIntakeService $service, PurchaseRequestService $purchaseRequests): InvoiceIntakeResource
    {
        $purchaseId = $request->validated('purchase_id');

        if ($request->filled('purchase_request_code')) {
            $purchaseId = $purchaseRequests->orderForInvoice($request->string('purchase_request_code')->toString(), $purchaseId)->id;
        }

        $intake = $service->confirm(
            $invoiceIntake,
            $request->validated('document_number'),
            $request->validated('items'),
            $request->user()->id,
            $purchaseId,
            $request->validated('annual_budget_id'),
            $request->boolean('allow_over_budget'),
            $request->user()->role === User::ROLE_DIRECTOR,
        );

        return new InvoiceIntakeResource($intake->load(['items.product.cajon.location', 'purchase', 'annualBudget']));
    }

    public function cancel(Request $request, InvoiceIntake $invoiceIntake, InvoiceIntakeService $service): InvoiceIntakeResource
    {
        Gate::authorize('update', $invoiceIntake);

        return new InvoiceIntakeResource($service->cancel($invoiceIntake, $request->user()->id));
    }
}
