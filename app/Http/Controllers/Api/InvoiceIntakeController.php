<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmInvoiceIntakeRequest;
use App\Http\Requests\StoreInvoiceIntakeRequest;
use App\Http\Resources\InvoiceIntakeResource;
use App\Models\InvoiceIntake;
use App\Services\InvoiceIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceIntakeController extends Controller
{
    /**
     * Query params: status, from, to, per_page. DIR-01 solo ve sus carreras.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', InvoiceIntake::class);

        $request->validate([
            'status' => ['sometimes', 'string', 'in:'.implode(',', InvoiceIntake::STATUSES)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = InvoiceIntake::query();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('received_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('received_at', '<=', $request->date('to'));
        }

        return InvoiceIntakeResource::collection($query->with(['purchase:id,code,status', 'annualBudget'])->orderByDesc('received_at')->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(StoreInvoiceIntakeRequest $request, InvoiceIntakeService $service): JsonResponse
    {
        Gate::authorize('create', InvoiceIntake::class);

        $intake = $service->createDraft($request->file('file'), $request->user()->id);

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

    public function confirm(ConfirmInvoiceIntakeRequest $request, InvoiceIntake $invoiceIntake, InvoiceIntakeService $service): InvoiceIntakeResource
    {
        $intake = $service->confirm(
            $invoiceIntake,
            $request->validated('document_number'),
            $request->validated('items'),
            $request->user()->id,
            $request->validated('purchase_id'),
        );

        return new InvoiceIntakeResource($intake->load(['items.product.cajon.location', 'purchase', 'annualBudget']));
    }

    public function cancel(Request $request, InvoiceIntake $invoiceIntake, InvoiceIntakeService $service): InvoiceIntakeResource
    {
        Gate::authorize('update', $invoiceIntake);

        return new InvoiceIntakeResource($service->cancel($invoiceIntake, $request->user()->id));
    }
}
