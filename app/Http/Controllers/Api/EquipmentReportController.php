<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIncidentReportRequest;
use App\Http\Resources\IncidentReportResource;
use App\Models\IncidentReport;
use App\Models\Product;
use App\Services\EquipmentPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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

    /**
     * REQ-13: Solicitud de reposición mediante informe de novedades (FU-05).
     *
     * POST /api/equipment/{id}/reports
     *
     * Acepta el formulario del informe (description/title/severity) más un
     * adjunto opcional (documento o imagen) y asocia el informe al equipo
     * para trazabilidad en su hoja de vida.
     */
    public function store(StoreIncidentReportRequest $request, Product $product): JsonResponse
    {
        $validated = $request->validated();
        $attachment = $request->file('attachment');

        $path = null;
        $originalName = null;

        if ($attachment !== null) {
            $path = $attachment->store('incidents', ['disk' => 'public']);

            if ($path === false) {
                throw ValidationException::withMessages([
                    'attachment' => 'No se pudo almacenar el adjunto del informe.',
                ]);
            }

            $originalName = $attachment->getClientOriginalName();
        }

        $report = $product->incidentReports()->create([
            'code' => IncidentReport::generateUniqueCode(),
            'reported_by' => $request->user()?->id,
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? ($validated['title'] ?? ''),
            'severity' => $validated['severity'] ?? IncidentReport::SEVERITY_MEDIUM,
            'status' => IncidentReport::STATUS_REPORTED,
            'attachment' => $path,
            'attachment_original_name' => $originalName,
        ]);

        $report->load('reporter');

        return response()->json([
            'message' => 'Informe de novedad registrado correctamente.',
            'data' => (new IncidentReportResource($report))->resolve(),
        ], 201);
    }
}
