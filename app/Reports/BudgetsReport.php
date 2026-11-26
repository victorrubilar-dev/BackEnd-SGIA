<?php

namespace App\Reports;

use App\Models\AnnualBudget;
use App\Models\BudgetMovement;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Presupuestos (`/compras/presupuestos`): ejecución por presupuesto anual (carrera y año, como
 * `GET /api/budgets/summary`) y los movimientos del ledger de esos presupuestos (`from`/`to` acotan los movimientos).
 */
final class BudgetsReport implements Report
{
    private const MOVEMENT_TYPES = [
        BudgetMovement::TYPE_INITIAL => 'Asignación inicial',
        BudgetMovement::TYPE_ADJUSTMENT => 'Ajuste',
        BudgetMovement::TYPE_RESERVE => 'Reserva',
        BudgetMovement::TYPE_RELEASE => 'Liberación de reserva',
        BudgetMovement::TYPE_CONSUME => 'Consumo',
        BudgetMovement::TYPE_RESTORE => 'Reposición',
    ];

    /**
     * @param  Builder<AnnualBudget>  $query  Presupuestos ya filtrados como en el resumen.
     */
    public function __construct(private readonly Request $request, private readonly Builder $query) {}

    public function title(): string
    {
        return 'Presupuestos';
    }

    public function filename(): string
    {
        return 'presupuestos';
    }

    public function audited(): bool
    {
        return false;
    }

    public function filters(): array
    {
        return ReportFilters::describe($this->request->query(), [
            'year' => 'Año',
            'career_id' => 'Carrera',
            'from' => 'Movimientos desde',
            'to' => 'Movimientos hasta',
        ]);
    }

    public function sheets(): array
    {
        $movements = BudgetMovement::query()
            ->whereIn('annual_budget_id', (clone $this->query)->select('annual_budgets.id'))
            ->when($this->request->filled('from'), fn ($q) => $q->where('budget_movements.created_at', '>=', $this->request->date('from')->startOfDay()))
            ->when($this->request->filled('to'), fn ($q) => $q->where('budget_movements.created_at', '<=', $this->request->date('to')->endOfDay()));

        return [
            // «Presupuestos» y no «Resumen»: ese nombre es de la hoja automática de `ReportExporter`.
            (new ReportSheet(
                'Presupuestos',
                ['Año', 'Carrera', 'Inicial', 'Reservado', 'Gastado', 'Saldo', 'Disponible', 'Ejecución %'],
                (clone $this->query)->with('career')->orderByDesc('year')->orderBy('career_id')->orderBy('id'),
                function (AnnualBudget $b): array {
                    $initial = (float) $b->initial_amount;
                    $reserved = (float) $b->reserved_amount;
                    $spent = $initial - (float) $b->available_amount;

                    return [
                        $b->year,
                        $b->career?->name,
                        $initial,
                        $reserved,
                        $spent,
                        (float) $b->available_amount,
                        (float) $b->available_amount - $reserved,
                        $initial > 0 ? round(($spent + $reserved) / $initial * 100, 2) : 0,
                    ];
                },
                [0 => XlsxWriter::INTEGER, 2 => XlsxWriter::MONEY, 3 => XlsxWriter::MONEY, 4 => XlsxWriter::MONEY, 5 => XlsxWriter::MONEY, 6 => XlsxWriter::MONEY],
                [8, 32, 15, 15, 15, 15, 15, 12],
            ))->summarized([], null, ['Inicial', 'Reservado', 'Gastado', 'Saldo', 'Disponible']),
            (new ReportSheet(
                'Movimientos',
                ['Fecha', 'Carrera', 'Año presupuesto', 'Tipo', 'Monto', 'OC', 'Factura', 'Usuario', 'Notas'],
                $movements
                    ->with(['annualBudget.career', 'purchase', 'invoiceIntake', 'user'])
                    ->orderBy('created_at')->orderBy('id'),
                fn (BudgetMovement $m): array => [
                    $m->created_at,
                    $m->annualBudget?->career?->name,
                    $m->annualBudget?->year,
                    self::MOVEMENT_TYPES[$m->type] ?? ReportValues::label($m->type),
                    $m->amount,
                    $m->purchase?->code,
                    $m->invoiceIntake?->document_number,
                    $m->user?->name,
                    $m->notes,
                ],
                [0 => XlsxWriter::DATETIME, 2 => XlsxWriter::INTEGER, 4 => XlsxWriter::MONEY],
                [17, 32, 8, 20, 15, 18, 16, 24, 40],
                yearOf: 0,
            ))->summarized(['Tipo', 'Carrera'], 'Monto'),
        ];
    }
}
