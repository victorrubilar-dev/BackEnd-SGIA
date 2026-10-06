<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use Illuminate\Support\Collection;

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
    public function topProducts(int $limit = 10): array
    {
        return $this->rankProducts('requests', $limit);
    }

    /**
     * Insumos fungibles más solicitados: se ordena por las unidades
     * entregadas (los fungibles se miden por unidades movidas, no por
     * cantidad de pedidos).
     *
     * @return array<int, array<string, mixed>>
     */
    public function topSupplies(int $limit = 10): array
    {
        return $this->rankProducts('units_lent', $limit);
    }

    /**
     * Distribución de consumo de insumos y préstamos por carrera/área
     * académica del solicitante.
     *
     * @return array<int, array<string, mixed>>
     */
    public function careersDistribution(): array
    {
        $loans = Loan::query()
            ->join('users', 'users.id', '=', 'loans.requested_by')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
            ->groupBy('users.area')
            ->selectRaw("COALESCE(users.area, 'Sin área') as area, COUNT(*) as loans")
            ->get()
            ->keyBy('area');

        $units = LoanItem::query()
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->join('users', 'users.id', '=', 'loans.requested_by')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
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
    public function leastDemanded(int $limit = 10): array
    {
        $aggregate = LoanItem::query()
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
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
    public function topTeachers(int $limit = 10): array
    {
        $requests = Loan::query()
            ->join('users', 'users.id', '=', 'loans.requested_by')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
            ->groupBy('loans.requested_by', 'users.name', 'users.area', 'users.email')
            ->selectRaw('loans.requested_by as user_id, users.name, users.area, users.email, COUNT(*) as requests')
            ->get()
            ->keyBy('user_id');

        $units = LoanItem::query()
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
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
     * Ranking compartido de productos: por pedidos (requests) o por
     * unidades entregadas (units_lent).
     *
     * @return array<int, array<string, mixed>>
     */
    private function rankProducts(string $orderBy, int $limit): array
    {
        return LoanItem::query()
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->join('products', 'products.id', '=', 'loan_items.product_id')
            ->where('loans.status', '!=', Loan::STATUS_REJECTED)
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
