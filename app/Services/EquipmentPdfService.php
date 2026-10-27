<?php

namespace App\Services;

use App\Models\InformeNovedad;
use App\Models\Producto;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

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
    public function technicalSheet(Producto $product): string
    {
        $product->loadMissing(['equipos.estante.sala', 'categoria', 'carrera']);

        $reports = $product->incidentReports()
            ->with('usuario')
            ->latest()
            ->limit(10)
            ->get();

        return $this->render(view('equipment.technical-sheet', [
            'product' => $product,
            'reports' => $reports,
            'reportsTotal' => $product->incidentReports()->count(),
        ]));
    }

    /**
     * Listado de informes de novedades del equipo en PDF.
     *
     * @param  Collection<int, InformeNovedad>  $reports
     */
    public function reportsList(Producto $product, Collection $reports): string
    {
        $product->loadMissing(['equipos.estante.sala', 'categoria', 'carrera']);

        return $this->render(view('equipment.reports', [
            'product' => $product,
            'reports' => $reports,
        ]));
    }

    /**
     * Respuesta HTTP de descarga para un PDF ya generado.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function download(string $pdf, string $filename)
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($pdf),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /**
     * Renderiza una vista HTML a PDF (A4, portrait).
     */
    private function render(View $view): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($view->render());
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
