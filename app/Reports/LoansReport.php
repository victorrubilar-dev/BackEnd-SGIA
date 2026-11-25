<?php

namespace App\Reports;

use App\Models\Loan;
use App\Models\LoanItem;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Historial de préstamos en Excel (`/prestamos/historial`, `GET /api/loans/export?format=xlsx`).
 * La hoja «Préstamos» mantiene las columnas del CSV (docs/exportaciones-y-campana.md) con fechas reales;
 * «Detalle» tiene una fila por unidad prestada (o por ítem, en los insumos).
 */
final class LoansReport implements Report
{
    /** @param  Builder<Loan>  $query  Consulta ya filtrada y visible para el usuario. */
    public function __construct(private readonly Request $request, private readonly Builder $query) {}

    public function title(): string
    {
        return 'Historial de préstamos';
    }

    public function filename(): string
    {
        return 'prestamos';
    }

    public function audited(): bool
    {
        return false;
    }

    public function filters(): array
    {
        $values = $this->request->query();
        $values['estado'] ??= $values['status'] ?? null;
        $values['tipo'] ??= $values['type'] ?? null;
        $values['sala'] ??= $values['room'] ?? null;
        $values['devuelto'] ??= $values['returned'] ?? null;
        if ($values['devuelto'] !== null && $values['devuelto'] !== '') {
            $values['devuelto'] = ReportFilters::yesNo($values['devuelto']);
        }
        $values['atrasado'] = filter_var($values['atrasado'] ?? $values['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'sí' : null;
        foreach (['estado', 'tipo'] as $field) {
            if (! empty($values[$field])) {
                $values[$field] = ReportValues::label((string) $values[$field]);
            }
        }

        return ReportFilters::describe($values, [
            'search' => 'Búsqueda',
            'from' => 'Desde',
            'to' => 'Hasta',
            'loan_date' => 'Fecha del préstamo',
            'estado' => 'Estado',
            'tipo' => 'Tipo',
            'career_id' => 'Carrera',
            'storeroom_id' => 'Pañol',
            'location_id' => 'Sala',
            'sala' => 'Sala (texto)',
            'devuelto' => 'Devuelto',
            'atrasado' => 'Atrasado',
        ]);
    }

    public function sheets(): array
    {
        return [
            new ReportSheet(
                'Préstamos',
                ['Código', 'Tipo', 'Estado', 'Solicitante', 'Asignatura', 'Carrera', 'Sala', 'Fecha', 'Vencimiento', 'Devuelto', 'Atrasado', 'Ítems'],
                (clone $this->query)->with(['items.units', 'requester', 'career']),
                fn (Loan $loan): array => [
                    $loan->code,
                    ReportValues::label($loan->type),
                    ReportValues::label($loan->status),
                    $loan->borrower_name ?? $loan->requester?->name,
                    $loan->subject,
                    $loan->career?->name,
                    $loan->room,
                    $loan->loan_date,
                    $loan->due_date ?? $loan->loan_date,
                    $loan->returned_at,
                    $loan->isOverdue() ? 'sí' : 'no',
                    $loan->items->map(fn (LoanItem $i) => "{$i->name} x{$i->quantity}".($i->units->isNotEmpty() ? ' ['.$i->units->pluck('code')->implode(', ').']' : ''))->implode('; '),
                ],
                [7 => XlsxWriter::DATE, 8 => XlsxWriter::DATE, 9 => XlsxWriter::DATETIME],
                [16, 11, 12, 28, 26, 30, 16, 12, 12, 17, 9, 60],
            ),
            ReportSheet::expanded(
                'Detalle',
                ['Préstamo', 'Fecha', 'Estado', 'Solicitante', 'Ítem', 'Código producto', 'Unidad', 'Cantidad', 'Entregado', 'Devuelto', 'Devuelto el', 'Condición'],
                (clone $this->query)->with(['items.units', 'items.product', 'requester']),
                function (Loan $loan): array {
                    $rows = [];
                    foreach ($loan->items as $item) {
                        $base = [$loan->code, $loan->loan_date, ReportValues::label($loan->status), $loan->borrower_name ?? $loan->requester?->name, $item->name, $item->product?->barcode];
                        $tail = [$item->returned_at, ReportValues::label($item->return_condition)];
                        if ($item->units->isEmpty()) {
                            $rows[] = [...$base, null, $item->quantity, $item->delivered_quantity, $item->returned_quantity, ...$tail];

                            continue;
                        }
                        foreach ($item->units as $unit) {
                            $rows[] = [...$base, $unit->code, 1, null, null, ...$tail];
                        }
                    }

                    return $rows;
                },
                [1 => XlsxWriter::DATE, 7 => XlsxWriter::INTEGER, 8 => XlsxWriter::INTEGER, 9 => XlsxWriter::INTEGER, 10 => XlsxWriter::DATETIME],
                [16, 12, 12, 28, 34, 18, 18, 10, 10, 9, 17, 12],
            ),
        ];
    }
}
