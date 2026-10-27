<?php

namespace App\Services;

use App\Models\Prestamo;
use App\Models\PrestamoDetalle;
use App\Models\Producto;
use App\Models\Usuario;
use Illuminate\Support\Collection;

/**
 * Agregados de demanda para los dashboards (FU-06, DIR-01/AD-01).
 */
class DashboardService
{
    /**
     * Productos más solicitados.
     */
    public function topProducts(int $limit = 10): array
    {
        return $this->rankProducts('requests', $limit);
    }

    /**
     * Insumos fungibles más solicitados.
     */
    public function topSupplies(int $limit = 10): array
    {
        return $this->rankProducts('units_lent', $limit);
    }

    /**
     * Distribución de consumo de insumos y préstamos por carrera/área académica.
     */
    public function careersDistribution(): array
    {
        $loans = Prestamo::query()
            ->join('usuarios', 'usuarios.id', '=', 'prestamos.profesor_id')
            ->leftJoin('carrera_usuario', 'carrera_usuario.usuario_id', '=', 'usuarios.id')
            ->leftJoin('carreras', 'carreras.id', '=', 'carrera_usuario.carrera_id')
            ->whereRaw("LOWER(prestamos.estado) != 'rechazado'")
            ->groupBy('carreras.nombre')
            ->selectRaw("COALESCE(carreras.nombre, 'General') as area, COUNT(DISTINCT prestamos.id) as loans")
            ->get()
            ->keyBy('area');

        $units = PrestamoDetalle::query()
            ->join('prestamos', 'prestamos.id', '=', 'prestamo_detalles.prestamo_id')
            ->join('usuarios', 'usuarios.id', '=', 'prestamos.profesor_id')
            ->leftJoin('carrera_usuario', 'carrera_usuario.usuario_id', '=', 'usuarios.id')
            ->leftJoin('carreras', 'carreras.id', '=', 'carrera_usuario.carrera_id')
            ->whereRaw("LOWER(prestamos.estado) != 'rechazado'")
            ->groupBy('carreras.nombre')
            ->selectRaw("COALESCE(carreras.nombre, 'General') as area, COALESCE(SUM(prestamo_detalles.cantidad_solicitada), 0) as units_lent")
            ->get()
            ->keyBy('area');

        if ($loans->isEmpty()) {
            return [
                ['area' => 'Informática y Telecomunicaciones', 'loans' => 0, 'units_lent' => 0],
            ];
        }

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
     * Equipos con menor rotación o sin uso.
     */
    public function leastDemanded(int $limit = 10): array
    {
        $aggregate = PrestamoDetalle::query()
            ->join('prestamos', 'prestamos.id', '=', 'prestamo_detalles.prestamo_id')
            ->join('equipos', 'equipos.id', '=', 'prestamo_detalles.equipo_id')
            ->whereRaw("LOWER(prestamos.estado) != 'rechazado'")
            ->groupBy('equipos.producto_id')
            ->selectRaw('equipos.producto_id, COUNT(*) as requests, COALESCE(SUM(prestamo_detalles.cantidad_solicitada), 0) as units_lent');

        return Producto::query()
            ->leftJoinSub($aggregate, 'agg', 'agg.producto_id', '=', 'productos.id')
            ->selectRaw('productos.*, COALESCE(agg.requests, 0) as requests, COALESCE(agg.units_lent, 0) as units_lent')
            ->orderBy('units_lent', 'asc')
            ->orderBy('requests', 'asc')
            ->orderBy('productos.nombre')
            ->limit($limit)
            ->get()
            ->map(fn (Producto $product) => [
                'product_id' => (int) $product->id,
                'name' => $product->nombre,
                'barcode' => $product->sku,
                'area' => $product->carrera?->nombre ?? 'General',
                'is_active' => true,
                'requests' => (int) $product->requests,
                'units_lent' => (int) $product->units_lent,
            ])
            ->values()
            ->all();
    }

    /**
     * Docentes con mayor cantidad de solicitudes de préstamos.
     */
    public function topTeachers(int $limit = 10): array
    {
        $requests = Prestamo::query()
            ->join('usuarios', 'usuarios.id', '=', 'prestamos.profesor_id')
            ->whereRaw("LOWER(prestamos.estado) != 'rechazado'")
            ->groupBy('prestamos.profesor_id', 'usuarios.nombre', 'usuarios.email')
            ->selectRaw('prestamos.profesor_id as user_id, usuarios.nombre, usuarios.email, COUNT(*) as requests')
            ->get()
            ->keyBy('user_id');

        $units = PrestamoDetalle::query()
            ->join('prestamos', 'prestamos.id', '=', 'prestamo_detalles.prestamo_id')
            ->whereRaw("LOWER(prestamos.estado) != 'rechazado'")
            ->groupBy('prestamos.profesor_id')
            ->selectRaw('prestamos.profesor_id as user_id, COALESCE(SUM(prestamo_detalles.cantidad_solicitada), 0) as units_lent')
            ->get()
            ->keyBy('user_id');

        return $requests
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'name' => $row->nombre,
                'area' => 'Área Informática',
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
     * Ranking compartido de productos.
     */
    private function rankProducts(string $orderBy, int $limit): array
    {
        return PrestamoDetalle::query()
            ->join('prestamos', 'prestamos.id', '=', 'prestamo_detalles.prestamo_id')
            ->join('equipos', 'equipos.id', '=', 'prestamo_detalles.equipo_id')
            ->join('productos', 'productos.id', '=', 'equipos.producto_id')
            ->whereRaw("LOWER(prestamos.estado) != 'rechazado'")
            ->groupBy('productos.id', 'productos.nombre', 'productos.sku')
            ->selectRaw('productos.id as product_id, productos.nombre, productos.sku as barcode, COUNT(*) as requests, COALESCE(SUM(prestamo_detalles.cantidad_solicitada), 0) as units_lent')
            ->orderByDesc($orderBy)
            ->orderByDesc('units_lent')
            ->orderBy('productos.nombre')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'name' => $row->nombre,
                'barcode' => $row->barcode,
                'area' => 'Área Informática',
                'requests' => (int) $row->requests,
                'units_lent' => (int) $row->units_lent,
            ])
            ->values()
            ->all();
    }
}
