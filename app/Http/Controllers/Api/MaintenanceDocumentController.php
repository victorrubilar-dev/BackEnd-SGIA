<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaintenanceDocumentRequest;
use App\Http\Resources\MaintenanceDocumentResource;
use App\Models\EquipmentUnit;
use App\Models\MaintenanceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos de mantenimiento por unidad (REQ-19). Los PDF viven en el disco privado `local`.
 */
class MaintenanceDocumentController extends Controller
{
    /**
     * GET /api/equipment-units/{equipmentUnit}/maintenance-documents
     *
     * Filtros: `document_type`, `expiring_within_days` (vigentes que vencen en N días),
     * `current` (solo vigentes o sin vencimiento).
     */
    public function index(Request $request, EquipmentUnit $equipmentUnit): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', MaintenanceDocument::class);

        $request->validate([
            'document_type' => ['nullable', Rule::in(MaintenanceDocument::TYPES)],
            'expiring_within_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ], [
            'document_type.in' => 'El tipo debe ser certificado_calibracion, informe_tecnico, manual, garantia u otro.',
            'expiring_within_days.integer' => 'expiring_within_days debe ser un número de días.',
        ]);

        $query = $equipmentUnit->maintenanceDocuments()->with('uploader');

        if ($request->filled('document_type')) {
            $query->where('document_type', $request->string('document_type'));
        }

        if ($request->filled('expiring_within_days')) {
            $query->whereBetween('valid_until', [now(), now()->addDays($request->integer('expiring_within_days'))->endOfDay()]);
        }

        if ($request->boolean('current')) {
            $query->current();
        }

        return MaintenanceDocumentResource::collection(
            $query->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($request))
        );
    }

    /**
     * POST /api/equipment-units/{equipmentUnit}/maintenance-documents (multipart)
     */
    public function store(StoreMaintenanceDocumentRequest $request, EquipmentUnit $equipmentUnit): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store(MaintenanceDocument::DIRECTORY, 'local');

        $document = MaintenanceDocument::create([
            'equipment_unit_id' => $equipmentUnit->id,
            'maintenance_record_id' => $request->input('maintenance_record_id'),
            'uploaded_by' => $request->user()->id,
            'document_type' => $request->string('document_type')->toString(),
            'title' => $request->string('title')->toString(),
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'size_bytes' => $file->getSize(),
            'valid_until' => $request->input('valid_until'),
        ]);

        return (new MaintenanceDocumentResource($document->load('uploader')))->response()->setStatusCode(201);
    }

    /**
     * GET /api/maintenance-documents/{maintenanceDocument}/file — descarga autenticada.
     */
    public function file(MaintenanceDocument $maintenanceDocument): StreamedResponse
    {
        Gate::authorize('view', $maintenanceDocument);

        abort_unless(Storage::disk('local')->exists($maintenanceDocument->file_path), 404);

        return Storage::disk('local')->download(
            $maintenanceDocument->file_path,
            $maintenanceDocument->original_name ?: str($maintenanceDocument->title)->slug().'.pdf'
        );
    }

    /**
     * DELETE /api/maintenance-documents/{maintenanceDocument} — borra también el archivo.
     */
    public function destroy(MaintenanceDocument $maintenanceDocument): Response
    {
        Gate::authorize('delete', $maintenanceDocument);

        $path = $maintenanceDocument->file_path;
        DB::transaction(fn () => $maintenanceDocument->delete());
        Storage::disk('local')->delete($path);

        return response()->noContent();
    }
}
