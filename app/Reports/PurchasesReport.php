<?php

namespace App\Reports;

use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Órdenes de compra (`/compras/ordenes`): las OC con los filtros de `GET /api/purchases` y sus ítems.
 */
final class PurchasesReport implements Report
{
    /**
     * @param  Builder<Purchase>  $query  Órdenes ya filtradas como en el listado.
     */
    public function __construct(private readonly Request $request, private readonly Builder $query) {}

    public function title(): string
    {
        return 'Órdenes de compra';
    }

    public function filename(): string
    {
        return 'ordenes-compra';
    }

    public function audited(): bool
    {
        return false;
    }

    public function filters(): array
    {
        $values = $this->request->query();
        $values['status'] ??= $values['estado'] ?? null;
        if (is_string($values['status'])) {
            $values['status'] = ReportValues::label($values['status']);
        }
        if (array_key_exists('linkable', $values) && filter_var($values['linkable'], FILTER_VALIDATE_BOOLEAN)) {
            $values['linkable'] = 'sí';
        } else {
            unset($values['linkable']);
        }

        return ReportFilters::describe($values, [
            'status' => 'Estado',
            'supplier_id' => 'Proveedor',
            'quotation_id' => 'Cotización',
            'search' => 'Búsqueda',
            'linkable' => 'Solo esperando factura',
        ]);
    }

    public function sheets(): array
    {
        return [
            new ReportSheet(
                'Órdenes',
                ['OC', 'N° OC proveedor', 'Proveedor', 'RUT proveedor', 'Solicitud', 'Carrera', 'Año presupuesto', 'Estado', 'Total', 'Reservado', 'Ítems', 'Creada', 'Llegada esperada', 'Recibida', 'Guía', 'Factura', 'Creada por', 'Notas'],
                (clone $this->query)
                    ->with(['supplier', 'purchaseRequest', 'annualBudget.career', 'creator'])
                    ->withCount('items')
                    ->orderByDesc('created_at')->orderByDesc('id'),
                fn (Purchase $p): array => [
                    $p->code,
                    $p->order_number,
                    $p->supplier?->name,
                    $p->supplier?->getAttribute('rut'),
                    $p->purchaseRequest?->code,
                    $p->annualBudget?->career?->name,
                    $p->annualBudget?->year,
                    ReportValues::label($p->status),
                    $p->total,
                    $p->reserved_amount,
                    $p->getAttribute('items_count'),
                    $p->created_at,
                    $p->expected_at,
                    $p->received_at,
                    $p->guide_number,
                    $p->invoice_number,
                    $p->creator?->name,
                    $p->notes,
                ],
                [6 => XlsxWriter::INTEGER, 8 => XlsxWriter::MONEY, 9 => XlsxWriter::MONEY, 10 => XlsxWriter::INTEGER, 11 => XlsxWriter::DATETIME, 12 => XlsxWriter::DATE, 13 => XlsxWriter::DATETIME],
                [18, 16, 30, 14, 15, 30, 10, 12, 14, 14, 8, 17, 15, 17, 14, 14, 24, 40],
                yearOf: 11,
            ),
            new ReportSheet(
                'Ítems',
                ['OC', 'Estado OC', 'Código producto', 'Producto', 'Cantidad', 'Precio unitario', 'Subtotal'],
                PurchaseItem::query()
                    ->whereIn('purchase_id', (clone $this->query)->select('purchases.id'))
                    ->with(['purchase', 'product'])
                    ->orderByDesc('purchase_id')->orderBy('id'),
                fn (PurchaseItem $item): array => [
                    $item->purchase?->code,
                    ReportValues::label($item->purchase?->status),
                    $item->product?->barcode,
                    $item->product->name ?? $item->name,
                    $item->quantity,
                    $item->unit_price,
                    $item->unit_price !== null ? round($item->unit_price * $item->quantity, 2) : null,
                ],
                [4 => XlsxWriter::INTEGER, 5 => XlsxWriter::MONEY, 6 => XlsxWriter::MONEY],
                [18, 12, 18, 40, 10, 15, 15],
            ),
        ];
    }
}
