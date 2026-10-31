<?php

namespace App\Services;

use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\Loan;
use App\Models\Product;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Genera los PDF del módulo FU-05 (fichas técnicas de equipos y
 * descarga de informes de novedades asociados a cada equipo).
 */
class EquipmentPdfService
{
    /**
     * Ficha técnica formal del equipo en PDF: datos del producto,
     * proveedor, ubicación física e historial de novedades.
     */
    public function technicalSheet(Product $product): string
    {
        $product->loadMissing(['supplier', 'cajon.location', 'location']);

        $reports = $product->incidentReports()
            ->with('reporter')
            ->latest()
            ->limit(10)
            ->get();

        return $this->render(view('equipment.technical-sheet', [
            'product' => $product,
            'units' => $product->units()->with('cajon.location')->orderBy('id')->limit(200)->get(),
            'unitsTotal' => $product->units()->count(),
            'reports' => $reports,
            'reportsTotal' => $product->incidentReports()->count(),
        ]));
    }

    /**
     * Listado de informes de novedades del equipo en PDF.
     *
     * @param  Collection<int, IncidentReport>  $reports
     */
    public function reportsList(Product $product, Collection $reports, ?EquipmentUnit $unit = null): string
    {
        $product->loadMissing(['supplier', 'cajon.location', 'location']);

        return $this->render(view('equipment.reports', [
            'product' => $product,
            'reports' => $reports,
            'unit' => $unit,
        ]));
    }

    /**
     * Hoja de vida de una unidad: producto, ubicación, mantenciones, préstamos e informes.
     */
    public function unitTechnicalSheet(EquipmentUnit $unit): string
    {
        $unit->load([
            'product.category',
            'cajon.location.storeroom',
            'loanItems.loan',
            'maintenanceRecords.responsible',
            'incidentReports',
        ]);

        return $this->render(view('equipment.unit-technical-sheet', ['unit' => $unit]));
    }

    /**
     * Historial de préstamos en PDF (A4), con el rango de fechas filtrado.
     *
     * @param  Collection<int, Loan>  $loans
     */
    public function loansReport(Collection $loans, ?string $from, ?string $to): string
    {
        return $this->render(view('loans.report', [
            'loans' => $loans,
            'from' => $from,
            'to' => $to,
        ]));
    }

    /**
     * Respuesta HTTP de descarga para un PDF ya generado.
     *
     * @return Response
     */
    public function download(string $pdf, string $filename)
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => strlen($pdf),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /**
     * Renderiza una vista HTML a PDF (A4, portrait).
     */
    private function render(View $view): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($view->render());
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
