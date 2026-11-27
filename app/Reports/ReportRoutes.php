<?php

namespace App\Reports;

/**
 * Lista cerrada de las rutas de reporte a las que se puede suscribir un usuario (docs/suscripcion-reportes.md,
 * decisión 8): el `id` y la ruta del catálogo del frontend, con la forma en que cada una recibe el período.
 */
final class ReportRoutes
{
    /** Estado del momento del envío: sin fechas. */
    public const SNAPSHOT = 'snapshot';

    /** Operaciones del período: `from` / `to`. */
    public const PERIOD = 'period';

    /** Presupuestos: `year` con el año del período enviado. */
    public const YEAR = 'year';

    /** id del catálogo => [ruta (con sus parámetros fijos), tipo]. */
    public const ALLOWED = [
        'inventario' => ['/products/export', self::SNAPSHOT],
        'unidades' => ['/equipment-units/export', self::SNAPSHOT],
        'prestamos' => ['/loans/export?format=excel', self::PERIOD],
        'alertas' => ['/alerts/export', self::PERIOD],
        'mantenciones' => ['/maintenance-records/export', self::PERIOD],
        'novedades' => ['/incident-reports/export', self::PERIOD],
        'estudiantes' => ['/students/export', self::SNAPSHOT],
        'ubicaciones' => ['/locations/export', self::SNAPSHOT],
        'solicitudes' => ['/purchase-requests/export', self::PERIOD],
        'ordenes' => ['/purchases/export', self::PERIOD],
        'facturas' => ['/invoice-intakes/export', self::PERIOD],
        'presupuestos' => ['/budgets/export', self::YEAR],
        'proveedores' => ['/suppliers/export', self::SNAPSHOT],
        'proveedores-mas-compras' => ['/reports/top-suppliers', self::PERIOD],
        'profesores-mas-prestamos' => ['/reports/top-teachers', self::PERIOD],
        'auditoria' => ['/audit-logs/export', self::PERIOD],
        'usuarios' => ['/users/export', self::SNAPSHOT],
        'monitoreo' => ['/monitoring/export', self::PERIOD],
        'informe-periodo' => ['/reports/period', self::PERIOD],
    ];

    /** Parámetros que nunca se guardan: el período se calcula en cada envío y `preview` es del envío. */
    private const NEVER_SAVED = ['from', 'to', 'preview'];

    public static function path(string $reportId): ?string
    {
        return self::ALLOWED[$reportId][0] ?? null;
    }

    public static function kind(string $reportId): string
    {
        return self::ALLOWED[$reportId][1] ?? self::SNAPSHOT;
    }

    /**
     * Los filtros que se guardan: sin período, sin `preview`, sin vacíos y sin lo que fija la propia ruta.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function clean(string $reportId, array $params): array
    {
        $skip = [...self::NEVER_SAVED, ...array_keys(self::fixed($reportId))];
        if (self::kind($reportId) === self::YEAR) {
            $skip[] = 'year';
        }

        return array_filter(
            array_diff_key($params, array_flip($skip)),
            fn ($value) => $value !== null && $value !== '',
        );
    }

    /**
     * Ruta sin consulta y parámetros completos de un envío: filtros guardados, período calculado y fijos de la ruta.
     *
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function request(string $reportId, array $params, string $from, string $to): array
    {
        $period = match (self::kind($reportId)) {
            self::PERIOD => ['from' => $from, 'to' => $to],
            self::YEAR => ['year' => substr($from, 0, 4)],
            default => [],
        };

        return [
            (string) strtok((string) self::path($reportId), '?'),
            array_merge(self::clean($reportId, $params), $period, self::fixed($reportId)),
        ];
    }

    /**
     * Filtros legibles que se muestran en «Mis suscripciones»: sin los del período, que cambia en cada envío.
     *
     * @param  array<string, string>  $filters  `Report::filters()` de un envío.
     * @return array<string, string>
     */
    public static function savedFilters(string $reportId, array $filters): array
    {
        return array_filter(
            $filters,
            fn (string $label) => ! preg_match('/\b(desde|hasta)$/iu', $label)
                && ! (self::kind($reportId) === self::YEAR && $label === 'Año'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return array<array-key, mixed> */
    private static function fixed(string $reportId): array
    {
        parse_str((string) parse_url((string) self::path($reportId), PHP_URL_QUERY), $fixed);

        return $fixed;
    }
}
