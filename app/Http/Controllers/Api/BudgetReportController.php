<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BudgetMovementResource;
use App\Http\Resources\BudgetSummaryResource;
use App\Models\AnnualBudget;
use App\Models\BudgetMovement;
use App\Models\InvoiceIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reportes de ejecución presupuestaria (REQ-24). DIR-01 solo ve las carreras que tiene asignadas.
 */
class BudgetReportController extends Controller
{
    /**
     * Query params: year, career_id. Una fila por carrera/año.
     */
    public function summary(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AnnualBudget::class);

        $request->validate(['year' => ['sometimes', 'integer'], 'career_id' => ['sometimes', 'integer']]);

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

        return BudgetSummaryResource::collection($query->orderByDesc('year')->orderBy('career_id')->get());
    }

    /**
     * Ledger paginado. Query params: type, from, to, purchase_id, invoice_intake_id, per_page.
     */
    public function movements(Request $request, AnnualBudget $annualBudget): AnonymousResourceCollection
    {
        Gate::authorize('view', $annualBudget);

        $request->validate([
            'type' => ['sometimes', 'string', 'in:'.implode(',', BudgetMovement::TYPES)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'purchase_id' => ['sometimes', 'integer'],
            'invoice_intake_id' => ['sometimes', 'integer'],
        ]);

        $query = $annualBudget->movements()->with('user');

        foreach (['type', 'purchase_id', 'invoice_intake_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        return BudgetMovementResource::collection(
            $query->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($request))
        );
    }

    /**
     * Gasto consumido (ingresos confirmados) agrupado. Query param: group_by = product|product_type|supplier.
     */
    public function spending(Request $request, AnnualBudget $annualBudget): JsonResponse
    {
        Gate::authorize('view', $annualBudget);

        $groupBy = $request->validate([
            'group_by' => ['sometimes', 'string', 'in:product,product_type,supplier'],
        ], [
            'group_by.in' => 'group_by debe ser: product, product_type o supplier.',
        ])['group_by'] ?? 'product';

        $query = DB::table('invoice_intake_items as ii')
            ->join('invoice_intakes as i', 'i.id', '=', 'ii.invoice_intake_id')
            ->where('i.annual_budget_id', $annualBudget->id)
            ->where('i.status', InvoiceIntake::STATUS_CONFIRMED);

        match ($groupBy) {
            'product' => $query->join('products as p', 'p.id', '=', 'ii.product_id')
                ->groupBy('p.id', 'p.name')->orderBy('p.name')
                ->selectRaw('p.id as id, p.name as label'),
            'product_type' => $query->join('products as p', 'p.id', '=', 'ii.product_id')
                ->groupBy('p.type')->orderBy('p.type')
                ->selectRaw('NULL::bigint as id, p.type as label'),
            'supplier' => $query->join('purchases as pu', 'pu.id', '=', 'i.purchase_id')
                ->join('suppliers as s', 's.id', '=', 'pu.supplier_id')
                ->groupBy('s.id', 's.name')->orderBy('s.name')
                ->selectRaw('s.id as id, s.name as label'),
        };

        $rows = $query->selectRaw('SUM(ii.quantity) as quantity, SUM(ii.subtotal) as amount')->get();

        return response()->json([
            'annual_budget_id' => $annualBudget->id,
            'group_by' => $groupBy,
            'total' => number_format((float) $rows->sum('amount'), 2, '.', ''),
            'data' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'label' => $row->label,
                'quantity' => (int) $row->quantity,
                'amount' => number_format((float) $row->amount, 2, '.', ''),
            ])->values(),
        ]);
    }

    /**
     * Serie mensual desde el ledger. Query param opcional `year` (año calendario; por defecto el del presupuesto).
     */
    public function monthly(Request $request, AnnualBudget $annualBudget): JsonResponse
    {
        Gate::authorize('view', $annualBudget);

        $request->validate(['year' => ['sometimes', 'integer', 'between:2000,2100']]);
        $year = $request->integer('year', $annualBudget->year);

        $sums = $annualBudget->movements()
            ->whereIn('type', [BudgetMovement::TYPE_RESERVE, BudgetMovement::TYPE_CONSUME, BudgetMovement::TYPE_RESTORE])
            ->whereYear('created_at', $year)
            ->selectRaw('EXTRACT(MONTH FROM created_at)::int as month, type, SUM(amount) as amount')
            ->groupBy('month', 'type')
            ->get()
            ->groupBy('month');

        $format = fn ($value) => number_format((float) $value, 2, '.', '');

        $data = collect(range(1, 12))->map(function (int $month) use ($sums, $format) {
            $byType = ($sums[$month] ?? collect())->pluck('amount', 'type');

            return [
                'month' => $month,
                'reserved' => $format($byType[BudgetMovement::TYPE_RESERVE] ?? 0),
                'consumed' => $format($byType[BudgetMovement::TYPE_CONSUME] ?? 0),
                'restored' => $format($byType[BudgetMovement::TYPE_RESTORE] ?? 0),
            ];
        });

        return response()->json(['annual_budget_id' => $annualBudget->id, 'year' => $year, 'data' => $data]);
    }
}
