<?php

namespace App\Reports;

use App\Models\EquipmentUnit;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Inventario (`/inventario`): productos con los filtros de `GET /api/products` y las unidades de los equipos y maquetas.
 */
final class ProductsReport implements Report
{
    public function __construct(private readonly Request $request, private readonly ProductService $products) {}

    public function title(): string
    {
        return 'Inventario';
    }

    public function filename(): string
    {
        return 'inventario';
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
        if (! empty($values['critical_only']) && filter_var($values['critical_only'], FILTER_VALIDATE_BOOLEAN)) {
            $values['critical_only'] = 'sí';
        } else {
            unset($values['critical_only']);
        }
        if (! empty($values['type'])) {
            $values['type'] = ReportValues::label((string) $values['type']);
        }

        return ReportFilters::describe($values, [
            'search' => 'Búsqueda',
            'type' => 'Tipo',
            'area' => 'Área',
            'is_active' => 'Activo',
            'critical_only' => 'Solo stock crítico',
            'sala' => 'Sala',
            'cajon' => 'Cajón',
            'cajon_id' => 'Cajón',
            'location_id' => 'Sala',
            'supplier_id' => 'Proveedor',
        ]);
    }

    public function sheets(): array
    {
        $query = fn (): Builder => $this->products->applyFilters(Product::query(), $this->request);

        return [
            new ReportSheet(
                'Productos',
                ['Código', 'Nombre', 'Tipo', 'Categoría', 'Carrera', 'Área', 'Sala', 'Cajón', 'Stock', 'Stock mínimo', 'En reparación', 'De baja', 'Stock crítico', 'Activo', 'Proveedor', 'Precio referencia', 'Código maestro', 'Creado'],
                $query()->with(['category', 'career', 'supplier', 'cajon.location', 'location'])->orderBy('name')->orderBy('id'),
                fn (Product $p): array => [
                    $p->barcode,
                    $p->name,
                    ReportValues::label($p->type),
                    $p->category?->name,
                    $p->career?->name,
                    $p->area,
                    ReportValues::room($p->cajon, $p->location),
                    $p->cajon?->codigo,
                    $p->quantity,
                    $p->stock_minimo,
                    $p->quantity_in_repair,
                    $p->quantity_written_off,
                    ReportValues::yesNo($p->quantity <= $p->stock_minimo),
                    ReportValues::yesNo($p->is_active),
                    $p->supplier?->name,
                    $p->reference_price,
                    $p->master_code,
                    $p->created_at,
                ],
                [8 => XlsxWriter::INTEGER, 9 => XlsxWriter::INTEGER, 10 => XlsxWriter::INTEGER, 11 => XlsxWriter::INTEGER, 15 => XlsxWriter::MONEY, 17 => XlsxWriter::DATE],
                [18, 40, 10, 22, 30, 16, 26, 16, 9, 12, 13, 9, 13, 8, 28, 16, 16, 12],
            ),
            new ReportSheet(
                'Unidades',
                ['Código', 'Producto', 'Tipo', 'N° de serie', 'Estado', 'Sala', 'Cajón', 'Última mantención', 'Próxima mantención'],
                EquipmentUnit::query()
                    ->whereIn('product_id', $query()->select('products.id'))
                    ->with(['product', 'cajon.location'])
                    ->orderBy('product_id')->orderBy('code')->orderBy('id'),
                fn (EquipmentUnit $u): array => [
                    $u->code,
                    $u->product?->name,
                    ReportValues::label($u->product?->type),
                    $u->serial_number,
                    ReportValues::label($u->status),
                    ReportValues::room($u->cajon),
                    $u->cajon?->codigo,
                    $u->last_maintenance_at,
                    $u->next_maintenance_at,
                ],
                [7 => XlsxWriter::DATE, 8 => XlsxWriter::DATE],
                [18, 40, 10, 20, 15, 26, 16, 17, 18],
            ),
        ];
    }
}
