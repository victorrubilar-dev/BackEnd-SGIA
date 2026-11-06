<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelMaintenanceRecordRequest;
use App\Http\Requests\FinishMaintenanceRecordRequest;
use App\Http\Requests\MaintenanceSummaryRequest;
use App\Http\Requests\StoreMaintenanceRecordRequest;
use App\Http\Requests\UpdateMaintenanceRecordRequest;
use App\Http\Resources\MaintenanceRecordResource;
use App\Http\Resources\MaintenanceSummaryResource;
use App\Models\EquipmentUnit;
use App\Models\MaintenanceRecord;
use App\Services\MaintenanceRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Registro de mantenciones de unidades de equipo (REQ-18).
 */
class MaintenanceRecordController extends Controller
{
    /** Relaciones de listado (evita N+1). */
    private const LIST_RELATIONS = ['equipmentUnit.product', 'responsible', 'externalSupplier'];

    /**
     * GET /api/maintenance-records
     */
    public function index(Request $request, MaintenanceRecordService $maintenance): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', MaintenanceRecord::class);

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ], ['from.date' => 'La fecha inicial no es válida.', 'to.date' => 'La fecha final no es válida.']);

        return MaintenanceRecordResource::collection(
            $maintenance->listQuery($request)->with(self::LIST_RELATIONS)->paginate($this->perPage($request))
        );
    }

    /**
     * GET /api/maintenance-records/summary — mantenciones por carrera.
     */
    public function summary(MaintenanceSummaryRequest $request, MaintenanceRecordService $maintenance): MaintenanceSummaryResource
    {
        return new MaintenanceSummaryResource($maintenance->summaryByCareer($request));
    }

    /**
     * GET /api/equipment-units/{equipmentUnit}/maintenance-records — historial de la unidad.
     */
    public function unitIndex(Request $request, EquipmentUnit $equipmentUnit): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', MaintenanceRecord::class);

        return MaintenanceRecordResource::collection(
            $equipmentUnit->maintenanceRecords()
                ->with(['responsible', 'externalSupplier', 'documents'])
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->paginate($this->perPage($request))
        );
    }

    /**
     * POST /api/equipment-units/{equipmentUnit}/maintenance-records
     */
    public function store(StoreMaintenanceRecordRequest $request, EquipmentUnit $equipmentUnit, MaintenanceRecordService $maintenance): JsonResponse
    {
        $record = $maintenance->start($equipmentUnit, $request->validated(), $request->user());

        return (new MaintenanceRecordResource($this->loadDetail($record)))->response()->setStatusCode(201);
    }

    /**
     * GET /api/maintenance-records/{maintenanceRecord}
     */
    public function show(MaintenanceRecord $maintenanceRecord): MaintenanceRecordResource
    {
        Gate::authorize('view', $maintenanceRecord);

        return new MaintenanceRecordResource($this->loadDetail($maintenanceRecord));
    }

    /**
     * PATCH /api/maintenance-records/{maintenanceRecord} — checklist, costos y notas (solo en curso).
     */
    public function update(UpdateMaintenanceRecordRequest $request, MaintenanceRecord $maintenanceRecord, MaintenanceRecordService $maintenance): MaintenanceRecordResource
    {
        $maintenance->update($maintenanceRecord, $request->validated(), $request->user());

        return new MaintenanceRecordResource($this->loadDetail($maintenanceRecord));
    }

    /**
     * POST /api/maintenance-records/{maintenanceRecord}/finish
     */
    public function finish(FinishMaintenanceRecordRequest $request, MaintenanceRecord $maintenanceRecord, MaintenanceRecordService $maintenance): MaintenanceRecordResource
    {
        $maintenance->finish(
            $maintenanceRecord,
            $request->string('result')->toString(),
            $request->input('technical_notes'),
            $request->user()
        );

        return new MaintenanceRecordResource($this->loadDetail($maintenanceRecord));
    }

    /**
     * POST /api/maintenance-records/{maintenanceRecord}/cancel
     */
    public function cancel(CancelMaintenanceRecordRequest $request, MaintenanceRecord $maintenanceRecord, MaintenanceRecordService $maintenance): MaintenanceRecordResource
    {
        $maintenance->cancel($maintenanceRecord, $request->input('reason'), $request->user());

        return new MaintenanceRecordResource($this->loadDetail($maintenanceRecord));
    }

    private function loadDetail(MaintenanceRecord $record): MaintenanceRecord
    {
        return $record->load([
            'equipmentUnit.product',
            'equipmentUnit.cajon.location.storeroom',
            'responsible',
            'externalSupplier',
            'incidentReport',
            'documents',
        ]);
    }
}
