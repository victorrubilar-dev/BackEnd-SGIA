<?php

namespace Database\Seeders;

use App\Models\ChartTemplate;
use Illuminate\Database\Seeder;

/**
 * Plantillas del dashboard personalizado (REQ-15). Las genéricas (`bar`, `line`, `pie`, `kpi`) solo
 * fijan la visualización; las demás traen una consulta completa que el gráfico hereda si no la envía.
 */
class ChartTemplateSeeder extends Seeder
{
    /**
     * code => [nombre, visualización, chart_type, entity, metric, group_by, period, descripción]
     *
     * @var array<string, array{0: string, 1: string, 2: ?string, 3: ?string, 4: ?string, 5: ?string, 6: ?string, 7: ?string}>
     */
    public const TEMPLATES = [
        'bar' => ['Gráfico de barras', 'bar', 'BAR', null, null, null, null, null],
        'line' => ['Gráfico de líneas', 'line', 'LINE', null, null, null, null, null],
        'pie' => ['Gráfico circular', 'pie', 'DOUGHNUT', null, null, null, null, null],
        'kpi' => ['Indicador (KPI)', 'kpi', null, null, null, null, null, null],
        'loans_trend' => ['Préstamos en el tiempo', 'line', 'LINE', 'LOANS', 'COUNT', null, 'LAST_30_DAYS', 'Préstamos por día en el período.'],
        'loans_by_status' => ['Préstamos por estado', 'pie', 'DOUGHNUT', 'LOANS', 'COUNT', 'status', 'LAST_30_DAYS', 'Pendientes, en proceso, procesados y rechazados.'],
        'top_supplies' => ['Insumos de mayor consumo', 'bar', 'BAR', 'SUPPLIES', 'TOTAL', null, 'SEMESTER_CURRENT', 'Unidades prestadas por insumo, con su stock actual.'],
        'equipment_by_condition' => ['Equipos por condición', 'pie', 'DOUGHNUT', 'EQUIPMENT', 'COUNT', 'physical_condition', 'LAST_30_DAYS', 'Unidades disponibles, prestadas, en taller y de baja.'],
        'usage_by_career' => ['Uso por carrera', 'pie', 'DOUGHNUT', 'CAREERS', 'COUNT', 'specialty', 'SEMESTER_CURRENT', 'Préstamos por carrera del solicitante.'],
        'top_teachers' => ['Ranking de docentes', 'bar', 'BAR', 'TEACHERS', 'COUNT', null, 'SEMESTER_CURRENT', 'Docentes con más préstamos.'],
        'alerts_by_criticality' => ['Alertas por criticidad', 'bar', 'BAR', 'ALERTS', 'COUNT', 'criticality', 'LAST_30_DAYS', 'Alertas de stock críticas y de advertencia.'],
        'maintenance_by_type' => ['Mantenciones por tipo', 'bar', 'BAR', 'MAINTENANCE', 'COUNT', 'maintenance_type', 'YEAR_TO_DATE', 'Preventivas, correctivas y calibraciones.'],
        'equipment_by_category' => ['Equipos por categoría', 'bar', 'BAR', 'EQUIPMENT', 'COUNT', 'hardware_category', 'LAST_30_DAYS', 'Unidades por categoría de hardware.'],
        'alerts_trend' => ['Alertas de stock en el tiempo', 'line', 'LINE', 'ALERTS', 'COUNT', null, 'SEMESTER_CURRENT', 'Evolución de las alertas de stock en el semestre.'],
        'maintenance_trend' => ['Incidencias en taller en el tiempo', 'line', 'LINE', 'MAINTENANCE', 'COUNT', null, 'YEAR_TO_DATE', 'Informes de falla y mantenciones durante el año.'],
        'supplies_by_career' => ['Demanda de insumos por carrera', 'pie', 'POLAR_AREA', 'SUPPLIES', 'TOTAL', 'career', 'SEMESTER_CURRENT', 'Unidades de insumos entregadas a cada carrera.'],
        'loans_by_subject' => ['Préstamos por asignatura', 'pie', 'POLAR_AREA', 'TEACHERS', 'COUNT', 'subject', 'SEMESTER_CURRENT', 'Préstamos de docentes agrupados por asignatura.'],
        'equipment_by_location' => ['Equipos por sala', 'pie', 'POLAR_AREA', 'EQUIPMENT', 'COUNT', 'location', 'SEMESTER_CURRENT', 'Unidades por sala o ubicación.'],
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $code => [$name, $visualization, $chartType, $entity, $metric, $groupBy, $period, $description]) {
            ChartTemplate::updateOrCreate(['code' => $code], [
                'name' => $name,
                'visualization_type' => $visualization,
                'chart_type' => $chartType,
                'entity' => $entity,
                'metric' => $metric,
                'group_by' => $groupBy,
                'period' => $period,
                'description' => $description,
            ]);
        }
    }
}
