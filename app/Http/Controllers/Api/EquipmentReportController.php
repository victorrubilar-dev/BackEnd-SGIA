<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIncidentReportRequest;
use App\Http\Requests\UpdateIncidentReportStatusRequest;
use App\Http\Resources\IncidentReportResource;
use App\Models\IncidentReport;
use App\Models\Product;
use App\Services\EquipmentPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            $filename = 'informes-'.($product->barcode ?: 'equipo-'.$product->id).'.pdf';

            return $pdfService->download($pdfService->reportsList($product, $reports), $filename);
        }

        $perPage = $this->perPage($request);

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
            $path = $attachment->store('incidents', 'local');

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

    /**
     * GET /api/incident-reports/{id}/attachment
     *
     * Descarga el adjunto desde el disco privado, solo para quienes pueden
     * ver informes.
     */
    public function attachment(IncidentReport $incidentReport): StreamedResponse
    {
        Gate::authorize('view', $incidentReport);

        abort_unless(
            $incidentReport->attachment && Storage::disk('local')->exists($incidentReport->attachment),
            404,
            'El informe no tiene adjunto.'
        );

        return Storage::disk('local')->download(
            $incidentReport->attachment,
            $incidentReport->attachment_original_name
        );
    }

    /**
     * Listado global de informes de novedades.
     *
     * GET /api/incident-reports
     * Query params: severity, status, product_id, search (código, título o descripción), per_page.
     */
    public function all(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', IncidentReport::class);

        $query = IncidentReport::with(['reporter', 'product'])->latest();

        foreach (['severity', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->string($field));
            }
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        if ($request->filled('search')) {
            $like = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $like)
                ->orWhere('title', 'ilike', $like)
                ->orWhere('description', 'ilike', $like));
        }

        return IncidentReportResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * Cambia el estado de atención del informe respetando las transiciones.
     *
     * PATCH /api/incident-reports/{id}/status
     */
    public function updateStatus(UpdateIncidentReportStatusRequest $request, IncidentReport $incidentReport): JsonResponse
    {
        $to = $request->validated('status');

        if (! in_array($to, $incidentReport->allowedTransitions(), true)) {
            throw ValidationException::withMessages([
                'status' => "No se puede pasar de \"{$incidentReport->status}\" a \"{$to}\".",
            ]);
        }

        $incidentReport->update(['status' => $to]);

        return response()->json([
            'message' => 'Estado del informe actualizado.',
            'data' => (new IncidentReportResource($incidentReport->load(['reporter', 'product'])))->resolve(),
        ]);
    }
}
