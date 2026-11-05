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
use Illuminate\Support\Facades\Storage;
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
     * Informe de novedades del docente: formulario con las casillas marcadas, foto y firma.
     */
    public function incidentReport(IncidentReport $report): string
    {
        return $this->render(view('incidents.report', [
            'report' => $report,
            'signature' => $this->imageDataUri($report->signature_path, 'image/png'),
            'photo' => $this->imageDataUri($report->evidence_photo_path),
        ]));
    }

    private function imageDataUri(?string $path, ?string $mime = null): ?string
    {
        $disk = Storage::disk('local');

        // dompdf necesita la extensión GD para incrustar PNG/JPG; sin ella el PDF omite las imágenes.
        if (! extension_loaded('gd') || ! $path || ! $disk->exists($path)) {
            return null;
        }

        return 'data:'.($mime ?? $disk->mimeType($path) ?: 'image/jpeg').';base64,'.base64_encode((string) $disk->get($path));
    }

    /**
     * Hoja de vida de una unidad: producto, ubicación, mantenciones, documentos vigentes, préstamos e informes.
     */
    public function unitTechnicalSheet(EquipmentUnit $unit): string
    {
        return $this->render($this->unitTechnicalSheetView($unit));
    }

    /**
     * Vista de la hoja de vida con sus relaciones cargadas (sin renderizar a PDF).
     */
    public function unitTechnicalSheetView(EquipmentUnit $unit): View
    {
        $unit->load([
            'product.category',
            'cajon.location.storeroom',
            'loanItems.loan',
            'maintenanceRecords.responsible',
            'maintenanceDocuments' => fn ($q) => $q->current()->orderBy('document_type'),
            'incidentReports',
        ]);

        return view('equipment.unit-technical-sheet', ['unit' => $unit]);
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
     * Etiqueta de 50x30 mm (impresora térmica): código de barras y código en texto (insumo o unidad).
     */
    public function label(string $code, BarcodeService $barcodes): string
    {
        return $this->render(view('products.label', [
            'code' => $code,
            // SVG en base64: DomPDF no lee el data URI sin base64 que usa el fallback sin GD.
            'barcodeUri' => 'data:image/svg+xml;base64,'.base64_encode($barcodes->generateSvg($code)),
        ]), [0, 0, 141.73, 85.04]);
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
    private function render(View $view, array|string $paper = 'A4'): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($view->render());
        $dompdf->setPaper($paper, 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
