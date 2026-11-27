<?php

namespace App\Reports;

/**
 * Marca una solicitud interna de envío por correo (docs/suscripcion-reportes.md, decisión 11). Va como atributo de la
 * solicitud, que no se puede enviar desde fuera. `ReportExporter` anota aquí los datos del reporte y no registra
 * `report.export`: el envío se audita como `report.email`. Es un objeto para que los vea quien creó la solicitud
 * aunque el controlador reciba una copia (FormRequest).
 */
final class ReportEmailChannel
{
    public const ATTRIBUTE = 'report_channel';

    public ?string $title = null;

    public ?string $filename = null;

    public ?string $slug = null;

    /** @var array<string, string> */
    public array $filters = [];

    public bool $personal = false;

    public function capture(Report $report, string $filename): void
    {
        $this->title = $report->title();
        $this->slug = $report->filename();
        $this->filename = $filename;
        $this->filters = $report->filters();
        $this->personal = $report->audited();
    }
}
