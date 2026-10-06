<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentReportResource;
use App\Models\IncidentReport;
use App\Models\Product;
use App\Services\EquipmentPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EquipmentReportController extends Controller
{
    /**
     * REQ-12: Informes de novedades asociados al equipo (FU-05).
     *
     * GET /api/equipment/{id}/reports               → listado JSON paginado.
     * GET /api/equipment/{id}/reports?format=pdf    → descarga en PDF.
     *
     * Query params: severity (leve|media|critica), status, per_page.
     */
    public function index(
        Request $request,
        Product $product,
        EquipmentPdfService $pdfService,
    ): AnonymousResourceCollection|Response {
        Gate::authorize('viewAny', IncidentReport::class);

        $query = $product->incidentReports()->with('reporter')->latest();

        if ($request->filled('severity')) {
            $query->where('severity', $request->string('severity'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->query('format') === 'pdf') {
            $reports = $query->limit(500)->get();
            $filename = 'informes-' . ($product->barcode ?: 'equipo-' . $product->id) . '.pdf';

            return $pdfService->download($pdfService->reportsList($product, $reports), $filename);
        }

        $perPage = max(1, min(100, $request->integer('per_page', 15)));

        return IncidentReportResource::collection($query->paginate($perPage));
    }
}
