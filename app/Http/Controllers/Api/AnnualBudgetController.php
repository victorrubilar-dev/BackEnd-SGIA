<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnnualBudgetRequest;
use App\Http\Requests\UpdateAnnualBudgetRequest;
use App\Http\Resources\AnnualBudgetResource;
use App\Models\AnnualBudget;
use App\Models\InvoiceIntake;
use App\Services\AnnualBudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AnnualBudgetController extends Controller
{
    /**
     * Query params: year, career_id, per_page. DIR-01 solo ve las carreras que tiene asignadas.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AnnualBudget::class);

        $query = AnnualBudget::query()->with('career');

        if (! $request->user()->isAdmin()) {
            $query->whereIn('career_id', $request->user()->careers()->select('careers.id'));
        }

        if ($request->filled('year')) {
            $query->where('year', $request->integer('year'));
        }

        if ($request->filled('career_id')) {
            $query->where('career_id', $request->integer('career_id'));
        }

        return AnnualBudgetResource::collection(
            $query->orderByDesc('year')->orderBy('career_id')->paginate($this->perPage($request))
        );
    }

    public function show(AnnualBudget $annualBudget): AnnualBudgetResource
    {
        Gate::authorize('view', $annualBudget);

        return new AnnualBudgetResource($annualBudget->load([
            'career',
            'invoiceIntakes' => fn ($q) => $q->where('status', InvoiceIntake::STATUS_CONFIRMED)->orderByDesc('received_at'),
        ]));
    }

    public function store(StoreAnnualBudgetRequest $request): JsonResponse
    {
        $data = $request->validated();

        $budget = AnnualBudget::create($data + [
            'available_amount' => $data['initial_amount'],
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return (new AnnualBudgetResource($budget->load('career')))->response()->setStatusCode(201);
    }

    public function update(UpdateAnnualBudgetRequest $request, AnnualBudget $annualBudget, AnnualBudgetService $service): AnnualBudgetResource
    {
        $budget = $service->updateInitialAmount($annualBudget, $request->validated('initial_amount'), $request->user()->id);

        return new AnnualBudgetResource($budget->load('career'));
    }

    public function destroy(AnnualBudget $annualBudget): Response
    {
        Gate::authorize('delete', $annualBudget);

        if ($annualBudget->invoiceIntakes()->exists()) {
            throw ValidationException::withMessages([
                'annual_budget' => 'No se puede eliminar un presupuesto que tiene ingresos de facturas.',
            ]);
        }

        $annualBudget->delete();

        return response()->noContent();
    }
}
