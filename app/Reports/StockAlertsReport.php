<?php

namespace App\Reports;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\StockAlert;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Alertas de stock (`/alertas`) con los filtros de la bandeja; sin `is_resolved`, solo las abiertas (como la pantalla).
 */
final class StockAlertsReport implements Report
{
    /** @param  Builder<StockAlert>  $query  Consulta ya filtrada por el controlador. */
    public function __construct(private readonly Request $request, private readonly Builder $query) {}

    public function title(): string
    {
        return 'Alertas de stock';
    }

    public function filename(): string
    {
        return 'alertas-stock';
    }

    public function audited(): bool
    {
        return false;
    }

    public function filters(): array
    {
        $values = $this->request->query();
        $values['is_resolved'] = array_key_exists('is_resolved', $values)
            ? (filter_var($values['is_resolved'], FILTER_VALIDATE_BOOLEAN) ? 'resueltas' : 'abiertas')
            : 'abiertas';
        if (! empty($values['alert_type'])) {
            $values['alert_type'] = self::type((string) $values['alert_type']);
        }
        $values['exclude_dismissed'] = filter_var($values['exclude_dismissed'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'sí' : null;

        return ReportFilters::describe($values, [
            'is_resolved' => 'Estado',
            'alert_type' => 'Tipo',
            'exclude_dismissed' => 'Sin las ocultadas por mí',
        ]);
    }

    public function sheets(): array
    {
        // Solicitud de compra abierta (no cancelada ni facturada) por producto, igual que la bandeja.
        $openRequests = PurchaseRequestItem::query()
            ->whereIn('product_id', (clone $this->query)->select('product_id'))
            ->whereHas('purchaseRequest', fn ($q) => $q->whereNotIn('status', [PurchaseRequest::STATUS_CANCELLED, PurchaseRequest::STATUS_INVOICED]))
            ->with('purchaseRequest:id,code,status')
            ->orderBy('id')
            ->get()
            ->unique('product_id')
            ->mapWithKeys(fn (PurchaseRequestItem $i) => [$i->product_id => $i->purchaseRequest?->code]);

        return [
            new ReportSheet(
                'Alertas',
                ['Fecha', 'Tipo', 'Código', 'Producto', 'Carrera', 'Sala', 'Cajón', 'Stock al alertar', 'Stock mínimo', 'Stock actual', 'Estado', 'Solicitud de compra abierta', 'Mensaje'],
                (clone $this->query)->orderByDesc('created_at')->orderByDesc('id'),
                fn (StockAlert $a): array => [
                    $a->created_at,
                    self::type($a->alert_type),
                    $a->product?->barcode,
                    $a->product?->name,
                    $a->product?->career?->name,
                    ReportValues::room($a->product?->cajon, $a->product?->location),
                    $a->product?->cajon?->codigo,
                    $a->current_stock,
                    $a->stock_minimo,
                    $a->product?->quantity,
                    $a->is_resolved ? 'Resuelta' : 'Abierta',
                    $openRequests->get($a->product_id),
                    $a->message,
                ],
                [0 => XlsxWriter::DATETIME, 7 => XlsxWriter::INTEGER, 8 => XlsxWriter::INTEGER, 9 => XlsxWriter::INTEGER],
                [17, 12, 18, 36, 28, 26, 16, 15, 12, 12, 10, 24, 60],
            ),
        ];
    }

    private static function type(?string $type): ?string
    {
        return match ($type) {
            StockAlert::TYPE_CRITICAL => 'Crítica',
            StockAlert::TYPE_WARNING => 'Advertencia',
            default => $type,
        };
    }
}
