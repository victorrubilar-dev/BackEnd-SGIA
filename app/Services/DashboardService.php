<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\StockAlert;
use App\Support\DashboardPeriod;

/**
 * Indicadores ejecutivos del panel (FU-06, DIR-01/AD-01). Los expone
 * `MetricsService::executiveKpis` por GraphQL (`POST /api/metrics`).
 */
class DashboardService
{
    /**
     * Indicadores ejecutivos (tarjetas del panel).
     *
     *  - assets: unidades disponibles (productos activos), prestadas ahora
     *    (ítems entregados y no devueltos), en taller y dadas de baja.
     *  - loans_today / active_loans / overdue_loans / overdue_rate: préstamos
     *    del día, vigentes (entregados sin devolver), vencidos y su porcentaje.
     *  - rotation_rate: unidades entregadas en el período sobre el stock total
     *    (disponible + prestado), en porcentaje.
     *
     * @return array<string, mixed>
     */
    public function kpis(DashboardPeriod $period): array
    {
        $available = (int) Product::where('is_active', true)->sum('quantity');
        $onLoan = (int) LoanItem::query()
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->where('loans.status', Loan::STATUS_PROCESSED)
            ->whereNull('loan_items.returned_at')
            ->sum('loan_items.quantity');

        $active = Loan::where('status', Loan::STATUS_PROCESSED)->whereNull('returned_at')->count();
        $overdue = Loan::overdue()->count();

        $unitsLent = (int) LoanItem::query()
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->where('loans.status', Loan::STATUS_PROCESSED)
            ->whereBetween('loans.loan_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->sum('loan_items.quantity');

        $stock = $available + $onLoan;

        return [
            'period' => $period->toArray(),
            'assets' => [
                'available' => $available,
                'on_loan' => $onLoan,
                'in_repair' => (int) Product::sum('quantity_in_repair'),
                'written_off' => (int) Product::sum('quantity_written_off'),
            ],
            'loans_today' => Loan::whereDate('loan_date', today())
                ->whereIn('status', [Loan::STATUS_IN_PROGRESS, Loan::STATUS_PROCESSED])
                ->count(),
            'active_loans' => $active,
            'overdue_loans' => $overdue,
            'overdue_rate' => $active > 0 ? round($overdue / $active * 100, 1) : 0.0,
            'unresolved_alerts' => StockAlert::where('is_resolved', false)->count(),
            'critical_alerts' => StockAlert::where('is_resolved', false)->where('alert_type', StockAlert::TYPE_CRITICAL)->count(),
            'units_lent' => $unitsLent,
            'rotation_rate' => $stock > 0 ? round($unitsLent / $stock * 100, 1) : 0.0,
        ];
    }
}
