<?php

namespace App\Reports;

use App\Models\EquipmentUnit;
use App\Services\EquipmentUnitService;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;

/**
 * Unidades de equipos y maquetas (`/equipos`) con los filtros de `GET /api/equipment-units`.
 */
final class EquipmentUnitsReport implements Report
{
    public function __construct(private readonly Request $request, private readonly EquipmentUnitService $units) {}

    public function title(): string
    {
        return 'Unidades de equipos';
    }

    public function filename(): string
    {
        return 'equipos';
    }

    public function audited(): bool
    {
        return false;
    }

    public function filters(): array
    {
        $values = $this->request->query();
        if (! empty($values['status'])) {
            $values['status'] = ReportValues::label((string) $values['status']);
        }
        if (filter_var($values['maintenance_due'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $values['maintenance_due'] = 'sí';
        } else {
            unset($values['maintenance_due']);
        }

        return ReportFilters::describe($values, [
            'search' => 'Búsqueda',
            'product_id' => 'Producto',
            'status' => 'Estado',
            'storeroom_id' => 'Pañol',
            'location_id' => 'Sala',
            'cajon_id' => 'Cajón',
            'maintenance_due' => 'Mantención vencida',
        ]);
    }

    public function sheets(): array
    {
        $query = $this->units->applyFilters(
            EquipmentUnit::query()->with(['product.category', 'product.career', 'cajon.location.storeroom'])->withCount(['loanItems', 'maintenanceRecords']),
            $this->request
        );

        return [
            new ReportSheet(
                'Unidades',
                ['Código', 'Producto', 'Tipo', 'Categoría', 'Carrera', 'N° de serie', 'Estado', 'Pañol', 'Sala', 'Cajón', 'Última mantención', 'Próxima mantención', 'Préstamos', 'Mantenciones', 'Alta'],
                $query->orderBy('product_id')->orderBy('code')->orderBy('id'),
                fn (EquipmentUnit $u): array => [
                    $u->code,
                    $u->product?->name,
                    ReportValues::label($u->product?->type),
                    $u->product?->category?->name,
                    $u->product?->career?->name,
                    $u->serial_number,
                    ReportValues::label($u->status),
                    $u->cajon?->location?->storeroom?->name,
                    ReportValues::room($u->cajon),
                    $u->cajon?->codigo,
                    $u->last_maintenance_at,
                    $u->next_maintenance_at,
                    $u->loan_items_count,
                    $u->maintenance_records_count,
                    $u->created_at,
                ],
                [10 => XlsxWriter::DATE, 11 => XlsxWriter::DATE, 12 => XlsxWriter::INTEGER, 13 => XlsxWriter::INTEGER, 14 => XlsxWriter::DATE],
                [18, 36, 10, 22, 30, 20, 15, 20, 26, 16, 17, 18, 11, 13, 12],
            ),
        ];
    }
}
