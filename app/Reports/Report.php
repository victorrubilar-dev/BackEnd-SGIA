<?php

namespace App\Reports;

/**
 * Un reporte Excel de una pantalla (docs/reportes-excel.md). Se arma con la solicitud ya validada
 * y lo descarga `ReportExporter`.
 */
interface Report
{
    /** Nombre legible del reporte («Inventario»), va en la hoja «Info». */
    public function title(): string;

    /** Prefijo del archivo (`inventario` → `inventario-20261125-101500.xlsx`). */
    public function filename(): string;

    /**
     * Hojas de datos en orden; `ReportExporter` agrega la hoja «Info» al final.
     *
     * @return list<ReportSheet>
     */
    public function sheets(): array;

    /**
     * Filtros aplicados en texto legible (etiqueta → valor). Vacío = reporte completo.
     *
     * @return array<string, string>
     */
    public function filters(): array;

    /** Si contiene datos personales (RUN, correo, IP): la exportación queda en `audit_logs`. */
    public function audited(): bool;
}
