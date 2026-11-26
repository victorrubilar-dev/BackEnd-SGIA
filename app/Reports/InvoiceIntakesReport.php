<?php

namespace App\Reports;

use App\Models\InvoiceIntake;
use App\Models\InvoiceIntakeItem;
use App\Models\Purchase;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Facturas (`/compras/facturas`): los ingresos de factura con los filtros de `GET /api/invoice-intakes` y sus ítems.
 */
final class InvoiceIntakesReport implements Report
{
    /**
     * @param  Builder<InvoiceIntake>  $query  Facturas ya filtradas como en el listado.
     */
    public function __construct(private readonly Request $request, private readonly Builder $query) {}

    public function title(): string
    {
        return 'Facturas';
    }

    public function filename(): string
    {
        return 'facturas';
    }

    public function audited(): bool
    {
        return false;
    }

    public function filters(): array
    {
        return ReportFilters::describe($this->request->query(), [
            'status' => ['Estado', fn ($v) => is_string($v) ? ReportValues::label($v) : null],
            'annual_budget_id' => 'Presupuesto',
            'purchase_id' => ['Orden de compra', fn ($v) => is_numeric($v) ? Purchase::query()->whereKey((int) $v)->value('code') : null],
            'purchase_request_code' => 'Solicitud',
            'from' => 'Recibida desde',
            'to' => 'Recibida hasta',
        ]);
    }

    public function sheets(): array
    {
        return [
            (new ReportSheet(
                'Facturas',
                ['Folio', 'Proveedor', 'RUT proveedor', 'OC', 'Solicitud', 'Carrera', 'Año presupuesto', 'Total', 'Estado', 'Recibida', 'Ingresada por'],
                (clone $this->query)
                    ->with(['purchase.supplier', 'purchase.purchaseRequest', 'annualBudget.career', 'user'])
                    ->orderByDesc('received_at')->orderByDesc('id'),
                fn (InvoiceIntake $i): array => [
                    $i->document_number,
                    $i->purchase->supplier->name ?? ($i->raw_ocr_payload['supplier_name'] ?? null),
                    $i->purchase?->supplier?->getAttribute('rut'),
                    $i->purchase?->code,
                    $i->purchase?->purchaseRequest?->code,
                    $i->annualBudget?->career?->name,
                    $i->annualBudget?->year,
                    $i->total_deducted,
                    ReportValues::label($i->status),
                    $i->received_at,
                    $i->user?->name,
                ],
                [6 => XlsxWriter::INTEGER, 7 => XlsxWriter::MONEY, 9 => XlsxWriter::DATETIME],
                [16, 30, 14, 18, 15, 30, 10, 14, 12, 17, 24],
                yearOf: 9,
            ))->summarized(['Estado', 'Carrera'], 'Total', ['Total']),
            (new ReportSheet(
                'Ítems',
                ['Folio', 'Estado factura', 'Código producto', 'Producto', 'Texto en la factura', 'Cantidad', 'Precio unitario', 'Subtotal'],
                InvoiceIntakeItem::query()
                    ->whereIn('invoice_intake_id', (clone $this->query)->select('invoice_intakes.id'))
                    ->with(['intake', 'product'])
                    ->orderByDesc('invoice_intake_id')->orderBy('id'),
                fn (InvoiceIntakeItem $item): array => [
                    $item->intake?->document_number,
                    ReportValues::label($item->intake?->status),
                    $item->product?->barcode,
                    $item->product?->name,
                    $item->detected_name,
                    $item->quantity,
                    $item->unit_price,
                    $item->subtotal,
                ],
                [5 => XlsxWriter::INTEGER, 6 => XlsxWriter::MONEY, 7 => XlsxWriter::MONEY],
                [16, 13, 18, 36, 36, 10, 15, 15],
            ))->summarized([], null, ['Subtotal']),
        ];
    }
}
