<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\StockAlert;
use App\Support\DashboardPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Agregados de demanda para los dashboards (FU-06, DIR-01/AD-01).
 *
 * Todos los indicadores se calculan sobre los préstamos que NO fueron
 * rechazados (pendiente, en_proceso, procesado): una solicitud rechazada
 * no representa demanda real de la carrera.
 */
class DashboardService
{
    /**
     * Productos más solicitados: se ordena por el número de veces que el
     * producto fue pedido (ítems de préstamo), con desempate por unidades.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topProducts(int $limit = 10, ?DashboardPeriod $period = null): array
    {
        return $this->rankProducts('requests', $limit, $period);
    }

    /**
     * Insumos fungibles más solicitados: se ordena por las unidades
     * entregadas (los fungibles se miden por unidades movidas, no por
     * cantidad de pedidos).
     *
     * @return array<int, array<string, mixed>>
     */
    public function topSupplies(int $limit = 10, ?DashboardPeriod $period = null): array
    {
        return $this->rankProducts('units_lent', $limit, $period);
    }

    /**
     * Distribución de consumo de insumos y préstamos por carrera/área
     * académica del solicitante.
     *
     * @return array<int, array<string, mixed>>
     */
    public function careersDistribution(?DashboardPeriod $period = null): array
    {
        $loans = Loan::query()
            ->join('users', 'users.id', '=', 'loans.requested_by')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
            ->tap(fn ($q) => $this->inPeriod($q, $period))
            ->groupBy('users.area')
            ->selectRaw("COALESCE(users.area, 'Sin área') as area, COUNT(*) as loans")
            ->get()
            ->keyBy('area');

        $units = LoanItem::query()
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->join('users', 'users.id', '=', 'loans.requested_by')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
            ->tap(fn ($q) => $this->inPeriod($q, $period))
            ->groupBy('users.area')
            ->selectRaw("COALESCE(users.area, 'Sin área') as area, COALESCE(SUM(loan_items.quantity), 0) as units_lent")
            ->get()
            ->keyBy('area');

        return $loans
            ->map(fn ($row) => [
                'area' => $row->area,
                'loans' => (int) $row->loans,
                'units_lent' => (int) ($units->get($row->area)?->units_lent ?? 0),
            ])
            ->sortByDesc('loans')
            ->values()
            ->all();
    }

    /**
     * Equipos con menor rotación o sin uso: incluye los que nunca fueron
     * prestados (0 unidades y 0 solicitudes) al inicio de la lista.
     *
     * @return array<int, array<string, mixed>>
     */
    public function leastDemanded(int $limit = 10, ?DashboardPeriod $period = null): array
    {
        $aggregate = LoanItem::query()
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
            ->tap(fn ($q) => $this->inPeriod($q, $period))
            ->groupBy('loan_items.product_id')
            ->selectRaw('loan_items.product_id, COUNT(*) as requests, COALESCE(SUM(loan_items.quantity), 0) as units_lent');

        return Product::query()
            ->leftJoinSub($aggregate, 'agg', 'agg.product_id', '=', 'products.id')
            ->selectRaw('products.*, COALESCE(agg.requests, 0) as requests, COALESCE(agg.units_lent, 0) as units_lent')
            ->orderBy('units_lent', 'asc')
            ->orderBy('requests', 'asc')
            ->orderBy('products.name')
            ->limit($limit)
            ->get()
            ->map(fn (Product $product) => $this->productRow($product))
            ->values()
            ->all();
    }

    /**
     * Docentes con mayor cantidad de solicitudes de préstamos.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topTeachers(int $limit = 10, ?DashboardPeriod $period = null): array
    {
        $requests = Loan::query()
            ->join('users', 'users.id', '=', 'loans.requested_by')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
            ->tap(fn ($q) => $this->inPeriod($q, $period))
            ->groupBy('loans.requested_by', 'users.name', 'users.area', 'users.email')
            ->selectRaw('loans.requested_by as user_id, users.name, users.area, users.email, COUNT(*) as requests')
            ->get()
            ->keyBy('user_id');

        $units = LoanItem::query()
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
            ->tap(fn ($q) => $this->inPeriod($q, $period))
            ->groupBy('loans.requested_by')
            ->selectRaw('loans.requested_by as user_id, COALESCE(SUM(loan_items.quantity), 0) as units_lent')
            ->get()
            ->keyBy('user_id');

        return $requests
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'name' => $row->name,
                'area' => $row->area,
                'email' => $row->email,
                'requests' => (int) $row->requests,
                'units_lent' => (int) ($units->get($row->user_id)?->units_lent ?? 0),
            ])
            ->sortBy([['requests', 'desc'], ['name', 'asc']])
            ->take($limit)
            ->values()
            ->all();
    }

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
            ->tap(fn ($q) => $this->inPeriod($q, $period))
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

    /**
     * Préstamos y unidades por docente y asignatura.
     *
     * @return array<int, array<string, mixed>>
     */
    public function loansByTeacher(int $limit = 10, ?DashboardPeriod $period = null): array
    {
        return Loan::query()
            ->join('users', 'users.id', '=', 'loans.requested_by')
            ->leftJoin('loan_items', 'loan_items.loan_id', '=', 'loans.id')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
            ->tap(fn ($q) => $this->inPeriod($q, $period))
            ->groupBy('loans.requested_by', 'users.name', 'loans.subject')
            ->selectRaw('loans.requested_by as user_id, users.name, loans.subject, COUNT(DISTINCT loans.id) as loans, COALESCE(SUM(loan_items.quantity), 0) as units_lent')
            ->orderByDesc('loans')
            ->orderBy('users.name')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'name' => $row->name,
                'subject' => $row->subject,
                'loans' => (int) $row->loans,
                'units_lent' => (int) $row->units_lent,
            ])
            ->all();
    }

    /**
     * Restringe una consulta con `loans` al período por fecha del préstamo.
     *
     * @param  Builder<Model>|\Illuminate\Database\Query\Builder  $query
     */
    private function inPeriod($query, ?DashboardPeriod $period): void
    {
        if ($period !== null) {
            $query->whereBetween('loans.loan_date', [$period->from->toDateString(), $period->to->toDateString()]);
        }
    }

    /**
     * Ranking compartido de productos: por pedidos (requests) o por
     * unidades entregadas (units_lent).
     *
     * @return array<int, array<string, mixed>>
     */
    private function rankProducts(string $orderBy, int $limit, ?DashboardPeriod $period): array
    {
        return LoanItem::query()
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->join('products', 'products.id', '=', 'loan_items.product_id')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
            ->tap(fn ($q) => $this->inPeriod($q, $period))
            ->groupBy('products.id', 'products.name', 'products.barcode', 'products.area')
            ->selectRaw('products.id as product_id, products.name, products.barcode, products.area, COUNT(*) as requests, COALESCE(SUM(loan_items.quantity), 0) as units_lent')
            ->orderByDesc($orderBy)
            ->orderByDesc('units_lent')
            ->orderBy('products.name')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'name' => $row->name,
                'barcode' => $row->barcode,
                'area' => $row->area,
                'requests' => (int) $row->requests,
                'units_lent' => (int) $row->units_lent,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function productRow(Product $product): array
    {
        return [
            'product_id' => (int) $product->id,
            'name' => $product->name,
            'barcode' => $product->barcode,
            'area' => $product->area,
            'is_active' => (bool) $product->is_active,
            'requests' => (int) $product->requests,
            'units_lent' => (int) $product->units_lent,
        ];
    }
}
