<?php

namespace App\Reports;

use App\Models\MaintenanceRecord;
use App\Services\MaintenanceRecordService;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;

/**
 * Mantenciones (`/mantenciones`) con los filtros de `GET /api/maintenance-records` (carrera, sin carrera, fechas).
 */
final class MaintenanceRecordsReport implements Report
{
    public function __construct(private readonly Request $request, private readonly MaintenanceRecordService $maintenance) {}

    public function title(): string
    {
        return 'Mantenciones';
    }

    public function filename(): string
    {
        return 'mantenciones';
    }

    public function audited(): bool
    {
        return false;
    }

    public function filters(): array
    {
        $values = $this->request->query();
        foreach (['status', 'maintenance_type', 'execution_mode'] as $field) {
            if (! empty($values[$field])) {
                $values[$field] = ReportValues::label((string) $values[$field]);
            }
        }
        $values['sin_carrera'] = filter_var($values['sin_carrera'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'sí' : null;

        return ReportFilters::describe($values, [
            'career_id' => 'Carrera',
            'sin_carrera' => 'Sin carrera',
            'from' => 'Desde',
            'to' => 'Hasta',
            'status' => 'Estado',
            'maintenance_type' => 'Tipo',
            'execution_mode' => 'Ejecución',
            'product_id' => 'Producto',
            'equipment_unit_id' => 'Unidad',
        ]);
    }

    public function sheets(): array
    {
        return [
            (new ReportSheet(
                'Mantenciones',
                ['Inicio', 'Término', 'Unidad', 'Producto', 'Carrera', 'Tipo', 'Ejecución', 'Estado', 'Responsable', 'Proveedor externo', 'REV-A', 'REV-B', 'REV-C', 'PFG', 'Requiere otra mantención', 'Costo', 'Repuestos', 'Notas técnicas', 'Próxima mantención'],
                $this->maintenance->listQuery($this->request)->with(['equipmentUnit.product.career', 'responsible', 'externalSupplier']),
                fn (MaintenanceRecord $m): array => [
                    $m->started_at,
                    $m->finished_at,
                    $m->equipmentUnit?->code,
                    $m->equipmentUnit?->product?->name,
                    $m->equipmentUnit?->product?->career?->name,
                    ReportValues::label($m->maintenance_type),
                    ReportValues::label($m->execution_mode),
                    ReportValues::label($m->status),
                    $m->responsible?->name,
                    $m->externalSupplier?->name,
                    ReportValues::yesNo($m->rev_a_approved),
                    ReportValues::yesNo($m->rev_b_approved),
                    $m->rev_c_approved === null ? 'no aplica' : ReportValues::yesNo($m->rev_c_approved),
                    ReportValues::yesNo($m->pfg_approved),
                    ReportValues::yesNo($m->requires_additional_maintenance),
                    $m->repair_cost,
                    $m->spare_parts_used,
                    $m->technical_notes,
                    $m->equipmentUnit?->next_maintenance_at,
                ],
                [0 => XlsxWriter::DATETIME, 1 => XlsxWriter::DATETIME, 15 => XlsxWriter::MONEY, 18 => XlsxWriter::DATE],
                [17, 17, 18, 34, 28, 12, 15, 11, 24, 24, 7, 7, 9, 6, 13, 13, 30, 60, 18],
                yearOf: 0,
            ))->summarized(['Tipo', 'Estado', 'Carrera'], 'Costo', ['Costo']),
        ];
    }
}
