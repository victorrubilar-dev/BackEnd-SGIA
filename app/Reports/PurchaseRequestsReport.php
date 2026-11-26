<?php

namespace App\Reports;

use App\Models\InvoiceIntake;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Services\PurchaseRequestStateMachine;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Solicitudes de compra (`/compras/solicitudes`): las solicitudes con los filtros de `GET /api/purchase-requests`
 * (fechas por etapa, cotización elegida, OC y factura) y sus ítems con el precio cotizado.
 */
final class PurchaseRequestsReport implements Report
{
    /**
     * @param  Builder<PurchaseRequest>  $query  Solicitudes ya filtradas como en el listado.
     */
    public function __construct(private readonly Request $request, private readonly Builder $query) {}

    public function title(): string
    {
        return 'Solicitudes de compra';
    }

    public function filename(): string
    {
        return 'solicitudes-compra';
    }

    public function audited(): bool
    {
        return false;
    }

    public function filters(): array
    {
        return ReportFilters::describe($this->request->query(), [
            'status' => ['Estado', fn ($v) => is_string($v) ? PurchaseRequestStateMachine::label($v) : null],
            'code' => 'Código',
            'from' => 'Creada desde',
            'to' => 'Creada hasta',
        ]);
    }

    public function sheets(): array
    {
        return [
            (new ReportSheet(
                'Solicitudes',
                ['Código', 'Estado', 'Carrera', 'Año presupuesto', 'Ítems', 'Cotizaciones', 'Proveedor elegido', 'Total', 'OC', 'N° OC proveedor', 'Creada por', 'Aprobada por', 'Creada', 'Aprobada', 'Enviada', 'Con OC', 'Factura', 'Facturada', 'Notas'],
                (clone $this->query)
                    ->with(['annualBudget.career', 'creator', 'approver', 'selectedQuotation.supplier', 'purchase.invoiceIntakes'])
                    ->withCount(['items', 'quotations'])
                    ->orderByDesc('id'),
                function (PurchaseRequest $r): array {
                    $invoice = $r->purchase?->invoiceIntakes->firstWhere('status', InvoiceIntake::STATUS_CONFIRMED);

                    return [
                        $r->code,
                        PurchaseRequestStateMachine::label($r->status),
                        $r->annualBudget?->career?->name,
                        $r->annualBudget?->year,
                        $r->getAttribute('items_count'),
                        $r->getAttribute('quotations_count'),
                        $r->selectedQuotation?->supplier?->name,
                        $r->purchase->total ?? $r->selectedQuotation?->total,
                        $r->purchase?->code,
                        $r->purchase?->order_number,
                        $r->creator?->name,
                        $r->approver?->name,
                        $r->created_at,
                        $r->approved_at,
                        $r->sent_at,
                        $r->purchase?->created_at,
                        $invoice?->document_number,
                        $invoice?->received_at,
                        $r->notes,
                    ];
                },
                [3 => XlsxWriter::INTEGER, 4 => XlsxWriter::INTEGER, 5 => XlsxWriter::INTEGER, 7 => XlsxWriter::MONEY, 12 => XlsxWriter::DATETIME, 13 => XlsxWriter::DATETIME, 14 => XlsxWriter::DATETIME, 15 => XlsxWriter::DATETIME, 17 => XlsxWriter::DATETIME],
                [15, 12, 30, 10, 8, 12, 28, 14, 18, 16, 24, 24, 17, 17, 17, 17, 14, 17, 40],
                yearOf: 12,
            ))->summarized(['Estado', 'Carrera'], 'Total', ['Total']),
            (new ReportSheet(
                'Ítems',
                ['Solicitud', 'Estado solicitud', 'Código producto', 'Producto', 'Cantidad', 'Precio unitario', 'Total'],
                PurchaseRequestItem::query()
                    ->whereIn('purchase_request_id', (clone $this->query)->select('purchase_requests.id'))
                    ->with(['product', 'purchaseRequest.selectedQuotation.items'])
                    ->orderByDesc('purchase_request_id')->orderBy('id'),
                function (PurchaseRequestItem $item): array {
                    $price = $item->purchaseRequest->selectedQuotation?->items->firstWhere('purchase_request_item_id', $item->id)?->unit_price;

                    return [
                        $item->purchaseRequest->code,
                        PurchaseRequestStateMachine::label($item->purchaseRequest->status),
                        $item->product?->barcode,
                        $item->product?->name,
                        $item->quantity,
                        $price,
                        $price !== null ? round((float) $price * $item->quantity, 2) : null,
                    ];
                },
                [4 => XlsxWriter::INTEGER, 5 => XlsxWriter::MONEY, 6 => XlsxWriter::MONEY],
                [15, 14, 18, 40, 10, 15, 15],
            ))->summarized([], null, ['Total']),
        ];
    }
}
