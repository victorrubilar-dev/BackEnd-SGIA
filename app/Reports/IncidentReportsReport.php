<?php

namespace App\Reports;

use App\Models\IncidentReport;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Informes de novedades (`/novedades`) con los filtros de la bandeja.
 */
final class IncidentReportsReport implements Report
{
    /** @param  Builder<IncidentReport>  $query  Consulta ya filtrada por el controlador. */
    public function __construct(private readonly Request $request, private readonly Builder $query) {}

    public function title(): string
    {
        return 'Informes de novedades';
    }

    public function filename(): string
    {
        return 'novedades';
    }

    public function audited(): bool
    {
        return false;
    }

    public function filters(): array
    {
        $values = $this->request->query();
        foreach (['status', 'severity', 'request_type'] as $field) {
            if (! empty($values[$field])) {
                $values[$field] = ReportValues::label((string) $values[$field]);
            }
        }

        return ReportFilters::describe($values, [
            'search' => 'Búsqueda',
            'status' => 'Estado',
            'severity' => 'Severidad',
            'request_type' => 'Tipo de solicitud',
            'product_id' => 'Producto',
            'equipment_unit_id' => 'Unidad',
            'from' => 'Desde',
            'to' => 'Hasta',
        ]);
    }

    public function sheets(): array
    {
        return [
            (new ReportSheet(
                'Informes',
                ['Código', 'Fecha', 'Tipo', 'Severidad', 'Estado', 'Título', 'Producto', 'Unidad', 'Carrera', 'Lugar', 'Reportado por', 'Docente', 'Asignatura', 'Problemas', 'Descripción', 'Sugerencia', 'Firmado', 'Actualizado'],
                (clone $this->query)->orderByDesc('created_at')->orderByDesc('id'),
                fn (IncidentReport $r): array => [
                    $r->code,
                    $r->report_date ?? $r->created_at,
                    ReportValues::label($r->request_type),
                    $r->severity ? $r->severityLabel() : null,
                    ReportValues::label($r->status),
                    $r->title,
                    $r->product?->name,
                    $r->equipmentUnit?->code,
                    $r->product?->career?->name,
                    $r->location ? trim(($r->location->code ? $r->location->code.' ' : '').($r->location->nombre ?? $r->location->sala ?? '')) : null,
                    $r->reporter?->name,
                    $r->teacher_name,
                    $r->subject,
                    self::problems($r->problems),
                    $r->description,
                    $r->suggestion,
                    $r->signed_at,
                    $r->updated_at,
                ],
                [1 => XlsxWriter::DATE, 16 => XlsxWriter::DATETIME, 17 => XlsxWriter::DATETIME],
                [20, 12, 13, 10, 13, 34, 32, 18, 28, 26, 24, 24, 26, 40, 60, 40, 17, 17],
                yearOf: 1,
            ))->summarized(['Severidad', 'Estado', 'Carrera']),
        ];
    }

    /**
     * Problemas del formulario del docente: «Falta de insumo: no quedan cables; Otros: …».
     *
     * @param  array<int, array{type: string, description: string}>|null  $problems
     */
    private static function problems(?array $problems): ?string
    {
        $text = collect($problems ?? [])
            ->map(fn (array $problem): string => (IncidentReport::PROBLEM_LABELS[$problem['type']] ?? $problem['type']).': '.$problem['description'])
            ->implode('; ');

        return $text !== '' ? $text : null;
    }
}
