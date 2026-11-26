<?php

namespace App\Reports;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «Proveedores con más compras» (`GET /api/reports/top-suppliers`, docs/reportes-rankings.md): ranking por monto
 * de las órdenes de compra creadas entre `from` y `to`, sin las canceladas ni las que no tienen proveedor,
 * y la hoja «Órdenes» con las órdenes de los proveedores del ranking.
 */
final class TopSuppliersReport implements Report
{
    /** @var Builder<Purchase> */
    private Builder $purchases;

    /** @var list<array{supplier_id: int, name: string, rut: ?string, orders: int, received: int, units: int, amount: float, last_at: Carbon}>|null */
    private ?array $ranking = null;

    public function __construct(private readonly Request $request)
    {
        $careerId = $request->filled('career_id') ? $request->integer('career_id') : null;

        $this->purchases = Purchase::query()
            ->whereBetween('created_at', [$request->date('from')->startOfDay(), $request->date('to')->endOfDay()])
            ->where('status', '!=', Purchase::STATUS_CANCELLED)
            ->whereNotNull('supplier_id')
            ->when($careerId, fn (Builder $q) => $q->whereHas('annualBudget', fn (Builder $b) => $b->where('career_id', $careerId)));
    }

    public function title(): string
    {
        return 'Proveedores con más compras';
    }

    public function filename(): string
    {
        return 'proveedores-mas-compras';
    }

    public function audited(): bool
    {
        return false;
    }

    public function filters(): array
    {
        return ReportFilters::describe($this->request->query(), [
            'from' => 'Desde',
            'to' => 'Hasta',
            'career_id' => 'Carrera',
            'top' => ['Mostrar', fn ($v) => is_numeric($v) ? 'los primeros '.(int) $v : null],
        ]);
    }

    public function sheets(): array
    {
        $ranking = $this->ranking();
        $places = TopRanking::places(array_column($ranking, 'amount'));
        $orders = (new PurchasesReport($this->request, (clone $this->purchases)->whereIn('supplier_id', array_column($ranking, 'supplier_id'))))
            ->sheets()[0];

        return [
            (new ReportSheet(
                'Ranking',
                ['Puesto', 'Proveedor', 'RUT', 'Órdenes', 'Recibidas', 'Unidades', 'Monto total', 'Promedio por orden', 'Última orden'],
                array_map(fn (array $row, int $place): array => [
                    $place,
                    $row['name'],
                    $row['rut'],
                    $row['orders'],
                    $row['received'],
                    $row['units'],
                    $row['amount'],
                    $row['orders'] > 0 ? round($row['amount'] / $row['orders'], 2) : null,
                    $row['last_at'],
                ], $ranking, $places),
                fn (array $row): array => $row,
                [0 => XlsxWriter::INTEGER, 3 => XlsxWriter::INTEGER, 4 => XlsxWriter::INTEGER, 5 => XlsxWriter::INTEGER, 6 => XlsxWriter::MONEY, 7 => XlsxWriter::MONEY, 8 => XlsxWriter::DATE],
                [8, 34, 14, 9, 10, 10, 16, 18, 13],
            ))->summarized([], null, ['Órdenes', 'Recibidas', 'Unidades', 'Monto total']),
            $orders->summarized([], null, ['Total', 'Reservado']),
        ];
    }

    /**
     * Cifras por proveedor, ordenadas por monto, órdenes y nombre, cortadas en `top`.
     *
     * @return list<array{supplier_id: int, name: string, rut: ?string, orders: int, received: int, units: int, amount: float, last_at: Carbon}>
     */
    private function ranking(): array
    {
        if ($this->ranking !== null) {
            return $this->ranking;
        }

        $stats = (clone $this->purchases)->reorder()->toBase()
            ->select('supplier_id')
            ->selectRaw('count(*) as orders')
            ->selectRaw('count(*) filter (where status = ?) as received', [Purchase::STATUS_COMPLETED])
            ->selectRaw('coalesce(sum(total), 0) as amount')
            ->selectRaw('max(created_at) as last_at')
            ->groupBy('supplier_id')
            ->get();

        $units = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereIn('purchase_items.purchase_id', (clone $this->purchases)->reorder()->select('purchases.id'))
            ->groupBy('purchases.supplier_id')
            ->selectRaw('purchases.supplier_id, sum(purchase_items.quantity) as units')
            ->pluck('units', 'supplier_id');

        $suppliers = Supplier::query()->whereIn('id', $stats->pluck('supplier_id'))->get()->keyBy('id');

        $rows = $stats->map(fn (object $s): array => [
            'supplier_id' => (int) $s->supplier_id,
            'name' => (string) $suppliers->get($s->supplier_id)?->name,
            'rut' => $suppliers->get($s->supplier_id)?->getAttribute('rut'),
            'orders' => (int) $s->orders,
            'received' => (int) $s->received,
            'units' => (int) ($units[$s->supplier_id] ?? 0),
            'amount' => (float) $s->amount,
            'last_at' => Carbon::parse($s->last_at),
        ])->sort(fn (array $a, array $b) => [$b['amount'], $b['orders'], $a['name']] <=> [$a['amount'], $a['orders'], $b['name']])->values()->all();

        return $this->ranking = TopRanking::cut($rows, $this->request);
    }
}
