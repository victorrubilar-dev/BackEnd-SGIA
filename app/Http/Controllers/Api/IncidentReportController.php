<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIncidentReportRequest;
use App\Http\Resources\IncidentReportResource;
use App\Models\IncidentReport;
use App\Services\EquipmentPdfService;
use App\Services\IncidentReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informe de novedades del docente (REQ-13): crear, ver los propios, firma y PDF.
 */
class IncidentReportController extends Controller
{
    private const RELATIONS = ['reporter', 'location', 'product', 'equipmentUnit'];

    /**
     * POST /api/incident-reports (solo PRO-01).
     */
    public function store(StoreIncidentReportRequest $request, IncidentReportService $service): JsonResponse
    {
        $report = $service->create($request->validated(), $request->user(), $request->file('evidence_photo'));

        return response()->json([
            'message' => 'Informe de novedades registrado correctamente.',
            'data' => (new IncidentReportResource($report))->resolve(),
        ], 201);
    }

    /**
     * GET /api/incident-reports/mine: informes del docente autenticado.
     * Query params: status, per_page.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $query = IncidentReport::with(self::RELATIONS)->where('reported_by', $request->user()->id)->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return IncidentReportResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * GET /api/incident-reports/{id}: personal de pañol/administración/dirección o el docente dueño.
     */
    public function show(IncidentReport $incidentReport): IncidentReportResource
    {
        Gate::authorize('view', $incidentReport);

        return new IncidentReportResource($incidentReport->load(self::RELATIONS));
    }

    /**
     * GET /api/incident-reports/{id}/pdf: formulario completo con la firma.
     */
    public function pdf(IncidentReport $incidentReport, EquipmentPdfService $pdfService): Response
    {
        Gate::authorize('view', $incidentReport);

        return $pdfService->download(
            $pdfService->incidentReport($incidentReport->load(self::RELATIONS)),
            'informe-novedades-'.$incidentReport->code.'.pdf'
        );
    }

    /**
     * GET /api/incident-reports/{id}/signature: imagen de la firma (disco privado).
     */
    public function signature(IncidentReport $incidentReport): StreamedResponse
    {
        Gate::authorize('view', $incidentReport);

        abort_unless(
            $incidentReport->signature_path && Storage::disk('local')->exists($incidentReport->signature_path),
            404,
            'El informe no tiene firma.'
        );

        return Storage::disk('local')->download($incidentReport->signature_path, 'firma-'.$incidentReport->code.'.png');
    }
}
