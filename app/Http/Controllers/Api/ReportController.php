<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Export\ExportPeriodReportRequest;
use App\Http\Requests\Export\ExportTopSuppliersRequest;
use App\Http\Requests\Export\ExportTopTeachersRequest;
use App\Http\Resources\ReportDownloadResource;
use App\Models\AuditLog;
use App\Reports\PeriodReport;
use App\Reports\ReportExporter;
use App\Reports\TopSuppliersReport;
use App\Reports\TopTeachersReport;
use App\Services\LoanService;
use App\Services\MaintenanceRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Centro de reportes (docs/centro-reportes.md): «Informe del período», rankings (docs/reportes-rankings.md) y «Mis descargas».
 */
class ReportController extends Controller
{
    /** Descargas que muestra «Mis descargas». */
    private const RECENT_DOWNLOADS = 20;

    /**
     * GET /api/reports/period: préstamos, mantenciones, novedades y alertas del período en un libro.
     */
    public function period(ExportPeriodReportRequest $request, LoanService $loans, MaintenanceRecordService $maintenance, ReportExporter $exporter): Response|JsonResponse
    {
        return $exporter->respond(new PeriodReport($request, $loans, $maintenance), $request);
    }

    /**
     * GET /api/reports/top-suppliers: proveedores con más compras del período.
     */
    public function topSuppliers(ExportTopSuppliersRequest $request, ReportExporter $exporter): Response|JsonResponse
    {
        return $exporter->respond(new TopSuppliersReport($request), $request);
    }

    /**
     * GET /api/reports/top-teachers: profesores con más préstamos del período.
     */
    public function topTeachers(ExportTopTeachersRequest $request, LoanService $loans, ReportExporter $exporter): Response|JsonResponse
    {
        return $exporter->respond(new TopTeachersReport($request, $loans), $request);
    }

    /**
     * GET /api/me/report-downloads: las últimas descargas de reportes del usuario, con la ruta y los parámetros para repetirlas.
     */
    public function downloads(Request $request): AnonymousResourceCollection
    {
        return ReportDownloadResource::collection(
            AuditLog::query()
                ->where('user_id', $request->user()->id)
                ->where('action', AuditLog::REPORT_EXPORT_ACTION)
                // Las exportaciones anteriores al centro de reportes no guardaban la ruta: no se pueden repetir.
                ->whereNotNull('new_values->path')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(self::RECENT_DOWNLOADS)
                ->get()
        );
    }
}
