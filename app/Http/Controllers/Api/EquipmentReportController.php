<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIncidentReportRequest;
use App\Http\Resources\IncidentReportResource;
use App\Models\Equipo;
use App\Models\Estante;
use App\Models\InformeNovedad;
use App\Models\Producto;
use App\Models\Sala;
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
     * GET /api/equipment/{product}/reports
     */
    public function index(
        Request $request,
        Producto $product,
        EquipmentPdfService $pdfService,
    ): AnonymousResourceCollection|Response {
        Gate::authorize('viewAny', InformeNovedad::class);

        $query = $product->incidentReports()->with('usuario')->latest('informes_novedades.id');

        if ($request->filled('severity')) {
            $query->where('tipo_solicitud', $request->string('severity'));
        }

        if ($request->filled('status')) {
            $query->where('estado', $request->string('status'));
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
     * POST /api/equipment/{product}/reports
     */
    public function store(StoreIncidentReportRequest $request, Producto $product): JsonResponse
    {
        $validated = $request->validated();
        $attachment = $request->file('attachment');

        $path = null;

        if ($attachment !== null) {
            $path = $attachment->store('incidents', ['disk' => 'public']);

            if ($path === false) {
                throw ValidationException::withMessages([
                    'attachment' => 'No se pudo almacenar el adjunto del informe.',
                ]);
            }
        }

        $defaultEstante = Estante::first() ?? Estante::create([
            'sala_id' => Sala::first()?->id ?? 1,
            'nombre' => 'Estante General',
            'codigo_barras' => 'EST-GEN-01',
        ]);

        $equipo = $product->equipos()->first() ?? Equipo::create([
            'producto_id' => $product->id,
            'estante_id' => $defaultEstante->id,
            'codigo_barras' => $product->sku . '-EQ01',
            'estado' => 'DISPONIBLE',
        ]);

        $report = InformeNovedad::create([
            'usuario_id' => $request->user()?->id,
            'equipo_id' => $equipo->id,
            'tipo_solicitud' => $validated['severity'] ?? $validated['title'] ?? InformeNovedad::SEVERITY_MEDIUM,
            'descripcion' => $validated['description'] ?? ($validated['title'] ?? ''),
            'foto_evidencia_url' => $path,
            'documento_adjunto_url' => $path,
            'estado' => InformeNovedad::STATUS_REPORTED,
        ]);

        $report->load('usuario');

        return response()->json([
            'message' => 'Informe de novedad registrado correctamente.',
            'data' => (new IncidentReportResource($report))->resolve(),
        ], 201);
    }
}
