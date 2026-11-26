<?php

namespace App\Reports;

use App\Models\IncidentReport;
use App\Models\Loan;
use App\Models\MaintenanceRecord;
use App\Models\StockAlert;
use App\Models\Storeroom;
use App\Models\User;
use App\Services\LoanService;
use App\Services\MaintenanceRecordService;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «Informe del período» (`GET /api/reports/period`, docs/centro-reportes.md, decisión 10): «Resumen general» y las
 * hojas de préstamos, mantenciones, novedades y alertas entre `from` y `to`, con su «Resumen».
 * Los préstamos respetan el pañol de PAN-01 (`Loan::visibleTo`) y `storeroom_id`; `career_id` acota las cuatro áreas.
 */
final class PeriodReport implements ScopedReport
{
    /** @var Builder<Loan> */
    private Builder $loans;

    /** @var Builder<MaintenanceRecord> */
    private Builder $maintenance;

    /** @var Builder<IncidentReport> */
    private Builder $incidents;

    /** @var Builder<StockAlert> */
    private Builder $alerts;

    public function __construct(
        private readonly Request $request,
        LoanService $loanService,
        private readonly MaintenanceRecordService $maintenanceService,
    ) {
        $from = $request->date('from')->startOfDay();
        $to = $request->date('to')->endOfDay();
        $careerId = $request->filled('career_id') ? $request->integer('career_id') : null;

        /** @var User $user */
        $user = $request->user();
        $this->loans = $loanService->applyHistoryFilters(Loan::visibleTo($user), $request)
            ->whereBetween('loan_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('loan_date')->orderBy('id');

        // `listQuery` ya aplica `from`/`to` (inicio) y `career_id`.
        $this->maintenance = $this->maintenanceService->listQuery($request);

        $this->incidents = IncidentReport::query()
            ->with(['reporter', 'product.career', 'equipmentUnit', 'location'])
            ->whereRaw('coalesce(report_date, created_at::date) between ? and ?', [$from->toDateString(), $to->toDateString()])
            ->when($careerId, fn (Builder $q) => $q->whereHas('product', fn (Builder $p) => $p->where('career_id', $careerId)));

        $this->alerts = StockAlert::query()
            ->with(['product.career', 'product.cajon.location', 'product.location'])
            ->whereBetween('created_at', [$from, $to])
            ->when($careerId, fn (Builder $q) => $q->whereHas('product', fn (Builder $p) => $p->where('career_id', $careerId)));
    }

    public function title(): string
    {
        return 'Informe del período';
    }

    public function filename(): string
    {
        return 'informe-periodo';
    }

    public function audited(): bool
    {
        return false;
    }

    public function scope(?User $user): string
    {
        $storeroomId = $user?->loanStoreroomId() ?? ($this->request->filled('storeroom_id') ? $this->request->integer('storeroom_id') : null);
        $loans = $storeroomId === null ? 'préstamos de todos los pañoles' : 'préstamos del pañol '.(Storeroom::query()->whereKey($storeroomId)->value('name') ?? "#{$storeroomId}");

        return ucfirst($loans).'; mantenciones, novedades y alertas de todo el sistema';
    }

    public function filters(): array
    {
        return ReportFilters::describe($this->request->query(), [
            'from' => 'Desde',
            'to' => 'Hasta',
            'career_id' => 'Carrera',
            'storeroom_id' => 'Pañol',
        ]);
    }

    public function sheets(): array
    {
        $loans = new LoansReport($this->request, $this->loans);
        $maintenance = new MaintenanceRecordsReport($this->request, $this->maintenanceService);
        $incidents = new IncidentReportsReport($this->request, $this->incidents);
        $alerts = new StockAlertsReport($this->request, $this->alerts);

        return [
            new ReportSheet(
                'Resumen general',
                ['Área', 'Indicador', 'Cantidad', 'Monto'],
                $this->overview(),
                fn (array $row): array => $row,
                [2 => XlsxWriter::INTEGER, 3 => XlsxWriter::MONEY],
                [16, 40, 11, 16],
            ),
            ...$loans->sheets(),
            ...$maintenance->sheets(),
            ...$incidents->sheets(),
            ...$alerts->sheets(),
        ];
    }

    /**
     * Cifras del período: préstamos (por estado, unidades prestadas, atrasados), mantenciones y su costo,
     * novedades por severidad y alertas (críticas).
     *
     * @return list<array{0: string, 1: string, 2: int, 3: float|int|null}>
     */
    private function overview(): array
    {
        $rows = [['Préstamos', 'Préstamos del período', (clone $this->loans)->count(), null]];
        foreach ((clone $this->loans)->reorder()->select('status', DB::raw('count(*) as total'))->groupBy('status')->orderBy('status')->pluck('total', 'status') as $status => $total) {
            $rows[] = ['Préstamos', 'Estado: '.ReportValues::label((string) $status), (int) $total, null];
        }
        $rows[] = ['Préstamos', 'Unidades de equipo prestadas', (int) DB::table('loan_item_units')
            ->join('loan_items', 'loan_items.id', '=', 'loan_item_units.loan_item_id')
            ->whereIn('loan_items.loan_id', (clone $this->loans)->reorder()->select('loans.id'))
            ->count(), null];
        $rows[] = ['Préstamos', 'Atrasados (entregados, sin devolver y vencidos)', (clone $this->loans)->overdue()->count(), null];

        $maintenance = (clone $this->maintenance)->reorder();
        $rows[] = ['Mantenciones', 'Mantenciones iniciadas', (clone $maintenance)->count(), (float) (clone $maintenance)->sum('maintenance_records.repair_cost')];
        foreach ((clone $maintenance)->select('maintenance_records.status', DB::raw('count(*) as total'))->groupBy('maintenance_records.status')->orderBy('maintenance_records.status')->pluck('total', 'status') as $status => $total) {
            $rows[] = ['Mantenciones', 'Estado: '.ReportValues::label((string) $status), (int) $total, null];
        }

        $rows[] = ['Novedades', 'Informes de novedades', (clone $this->incidents)->count(), null];
        foreach ((clone $this->incidents)->select('severity', DB::raw('count(*) as total'))->groupBy('severity')->orderBy('severity')->pluck('total', 'severity') as $severity => $total) {
            $rows[] = ['Novedades', 'Severidad: '.(IncidentReport::SEVERITY_LABELS[$severity] ?? ReportValues::label((string) $severity)), (int) $total, null];
        }

        $rows[] = ['Alertas', 'Alertas de stock', (clone $this->alerts)->count(), null];
        $rows[] = ['Alertas', 'Alertas críticas', (clone $this->alerts)->where('alert_type', StockAlert::TYPE_CRITICAL)->count(), null];

        return $rows;
    }
}
