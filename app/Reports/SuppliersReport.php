<?php

namespace App\Reports;

use App\Enums\SupplierArea;
use App\Models\Supplier;
use App\Models\SupplierAreaAssignment;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Proveedores (`/compras/proveedores`) con los filtros de `GET /api/suppliers`.
 */
final class SuppliersReport implements Report
{
    /**
     * @param  Builder<Supplier>  $query  Proveedores ya filtrados como en el listado.
     */
    public function __construct(private readonly Request $request, private readonly Builder $query) {}

    public function title(): string
    {
        return 'Proveedores';
    }

    public function filename(): string
    {
        return 'proveedores';
    }

    public function audited(): bool
    {
        return false;
    }

    public function filters(): array
    {
        $values = $this->request->query();
        if (array_key_exists('is_active', $values)) {
            $values['is_active'] = ReportFilters::yesNo($values['is_active']);
        }

        return ReportFilters::describe($values, [
            'area' => ['Área', fn ($v) => is_string($v) ? SupplierArea::tryFrom($v)?->label() : null],
            'category' => 'Categoría',
            'is_active' => 'Activo',
            'search' => 'Búsqueda',
        ]);
    }

    public function sheets(): array
    {
        return [
            new ReportSheet(
                'Proveedores',
                ['RUT', 'Razón social', 'Áreas', 'Categoría', 'Contacto', 'Correo', 'Teléfono', 'Dirección', 'Activo', 'Productos', 'Creado'],
                (clone $this->query)->with('areas')->withCount('products')->orderBy('name')->orderBy('id'),
                fn (Supplier $s): array => [
                    $s->getAttribute('rut'),
                    $s->name,
                    $s->areas->map(fn (SupplierAreaAssignment $a) => $a->area->label())->sort()->implode(', ') ?: null,
                    $s->category,
                    $s->contact_name,
                    $s->email,
                    $s->phone,
                    $s->address,
                    ReportValues::yesNo($s->is_active),
                    $s->getAttribute('products_count'),
                    $s->created_at,
                ],
                [9 => XlsxWriter::INTEGER, 10 => XlsxWriter::DATE],
                [14, 34, 30, 18, 26, 30, 16, 34, 8, 10, 12],
            ),
        ];
    }
}
