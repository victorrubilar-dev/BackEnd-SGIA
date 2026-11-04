<?php

namespace App\Services;

use App\Exceptions\InvalidMaintenanceRecordStatusTransition;
use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro de mantenciones (REQ-18). La unidad pasa a `en_reparacion` mientras la mantención
 * está `en_curso`; al finalizar queda `disponible` u `dado_de_baja`, y al anular vuelve a su
 * estado previo. Un informe de novedades asociado acompaña esos cambios.
 */
class MaintenanceRecordService
{
    public function __construct(private readonly EquipmentUnitService $units) {}

    /**
     * Filtros del listado: unidad, producto, estado, tipo, modalidad y rango de inicio.
     * Orden: prioridad de mantención del producto (`A` primero) y fecha de inicio (reciente primero).
     */
    public function listQuery(Request $request): Builder
    {
        $query = MaintenanceRecord::query()
            ->select('maintenance_records.*')
            ->join('equipment_units', 'equipment_units.id', '=', 'maintenance_records.equipment_unit_id')
            ->join('products', 'products.id', '=', 'equipment_units.product_id');

        foreach (['equipment_unit_id', 'status', 'maintenance_type', 'execution_mode'] as $field) {
            if ($request->filled($field)) {
                $query->where("maintenance_records.{$field}", $request->input($field));
            }
        }

        if ($request->filled('product_id')) {
            $query->where('equipment_units.product_id', $request->integer('product_id'));
        }

        if ($request->filled('from')) {
            $query->where('maintenance_records.started_at', '>=', $request->date('from')->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('maintenance_records.started_at', '<=', $request->date('to')->endOfDay());
        }

        return $query
            ->orderBy('products.maintenance_priority')
            ->orderByDesc('maintenance_records.started_at')
            ->orderByDesc('maintenance_records.id');
    }

    /**
     * Abre una mantención: la unidad pasa a `en_reparacion` y el informe asociado, si lo hay,
     * a `en_reparacion`.
     *
     * @param  array<string, mixed>  $data  validados por StoreMaintenanceRecordRequest
     *
     * @throws ValidationException
     */
    public function start(EquipmentUnit $unit, array $data, User $user): MaintenanceRecord
    {
        return DB::transaction(function () use ($unit, $data, $user) {
            $locked = EquipmentUnit::whereKey($unit->id)->lockForUpdate()->firstOrFail();

            $blocked = match ($locked->status) {
                EquipmentUnit::STATUS_ON_LOAN => 'La unidad está prestada; registra su devolución antes de enviarla a mantención.',
                EquipmentUnit::STATUS_WRITTEN_OFF => 'La unidad está dada de baja y no admite mantenciones.',
                default => null,
            };
            if ($blocked !== null) {
                throw ValidationException::withMessages(['equipment_unit_id' => $blocked]);
            }

            if ($locked->maintenanceRecords()->where('status', MaintenanceRecord::STATUS_IN_PROGRESS)->exists()) {
                throw ValidationException::withMessages([
                    'equipment_unit_id' => 'La unidad ya tiene una mantención en curso.',
                ]);
            }

            $previousReportStatus = null;
            if (! empty($data['incident_report_id'])) {
                $report = IncidentReport::whereKey($data['incident_report_id'])->lockForUpdate()->firstOrFail();
                if (in_array($report->status, [IncidentReport::STATUS_REPAIRED, IncidentReport::STATUS_DISCARDED], true)) {
                    throw ValidationException::withMessages([
                        'incident_report_id' => 'El informe de novedades ya está cerrado.',
                    ]);
                }
                $previousReportStatus = $report->status;
                if ($report->status !== IncidentReport::STATUS_IN_REPAIR) {
                    $report->forceFill(['status' => IncidentReport::STATUS_IN_REPAIR])->save();
                }
            }

            $record = new MaintenanceRecord([
                'equipment_unit_id' => $locked->id,
                'responsible_id' => $data['responsible_id'] ?? $user->id,
                'external_supplier_id' => ($data['execution_mode'] ?? null) === MaintenanceRecord::MODE_EXTERNAL
                    ? $data['external_supplier_id']
                    : null,
                'incident_report_id' => $data['incident_report_id'] ?? null,
                'maintenance_type' => $data['maintenance_type'],
                'execution_mode' => $data['execution_mode'] ?? MaintenanceRecord::MODE_OWN,
                'technical_notes' => $data['technical_notes'] ?? null,
                'status' => MaintenanceRecord::STATUS_IN_PROGRESS,
                'started_at' => now(),
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            $record->forceFill([
                'previous_unit_status' => $locked->status,
                'previous_report_status' => $previousReportStatus,
            ])->save();

            if ($locked->status !== EquipmentUnit::STATUS_IN_REPAIR) {
                $this->units->changeStatus($locked, EquipmentUnit::STATUS_IN_REPAIR, $user->id, fromMaintenance: true);
            }

            return $record;
        });
    }

    /**
     * Edita checklist, costos y notas de una mantención en curso.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(MaintenanceRecord $record, array $data, User $user): MaintenanceRecord
    {
        if ($record->status !== MaintenanceRecord::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages([
                'status' => 'Solo se puede editar una mantención en curso.',
            ]);
        }

        $record->update($data + ['updated_by' => $user->id]);

        return $record;
    }

    /**
     * Finaliza la mantención. `operativo` exige el checklist aprobado y deja la unidad
     * `disponible`; `baja` la deja `dado_de_baja` sin exigir checklist.
     *
     * @throws InvalidMaintenanceRecordStatusTransition|ValidationException
     */
    public function finish(MaintenanceRecord $record, string $result, ?string $notes, User $user): MaintenanceRecord
    {
        return DB::transaction(function () use ($record, $result, $notes, $user) {
            $locked = MaintenanceRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->assertTransition($locked, MaintenanceRecord::STATUS_FINISHED);

            if ($result === MaintenanceRecord::RESULT_OPERATIONAL && ! $locked->checklistComplete()) {
                throw ValidationException::withMessages([
                    'checklist' => 'Para dejar la unidad operativa deben estar aprobadas REV-A, REV-B, PFG y REV-C (si aplica).',
                ]);
            }

            $locked->forceFill([
                'result' => $result,
                'updated_by' => $user->id,
                'technical_notes' => $notes ?? $locked->technical_notes,
            ]);
            $locked->finish();

            $unit = EquipmentUnit::findOrFail($locked->equipment_unit_id);
            $target = $result === MaintenanceRecord::RESULT_OPERATIONAL
                ? EquipmentUnit::STATUS_AVAILABLE
                : EquipmentUnit::STATUS_WRITTEN_OFF;

            if ($result === MaintenanceRecord::RESULT_WRITE_OFF) {
                $unit->forceFill(['next_maintenance_at' => null])->save();
            }
            if ($unit->status !== $target) {
                $this->units->changeStatus($unit, $target, $user->id, fromMaintenance: true);
            }

            if ($locked->incident_report_id !== null) {
                $report = IncidentReport::findOrFail($locked->incident_report_id);
                if (! in_array($report->status, [IncidentReport::STATUS_REPAIRED, IncidentReport::STATUS_DISCARDED], true)) {
                    $report->forceFill([
                        'status' => $result === MaintenanceRecord::RESULT_OPERATIONAL
                            ? IncidentReport::STATUS_REPAIRED
                            : IncidentReport::STATUS_DISCARDED,
                    ])->save();
                }
            }

            $record->setRawAttributes($locked->getAttributes(), true);

            return $record;
        });
    }

    /**
     * Anula la mantención: la unidad y el informe asociado vuelven al estado que tenían al abrirla.
     *
     * @throws InvalidMaintenanceRecordStatusTransition
     */
    public function cancel(MaintenanceRecord $record, ?string $reason, User $user): MaintenanceRecord
    {
        return DB::transaction(function () use ($record, $reason, $user) {
            $locked = MaintenanceRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->assertTransition($locked, MaintenanceRecord::STATUS_CANCELLED);

            $notes = $reason !== null && $reason !== ''
                ? trim(($locked->technical_notes ? $locked->technical_notes."\n" : '').'Anulada: '.$reason)
                : $locked->technical_notes;

            $locked->forceFill([
                'status' => MaintenanceRecord::STATUS_CANCELLED,
                'finished_at' => now(),
                'technical_notes' => $notes,
                'updated_by' => $user->id,
            ])->save();

            $unit = EquipmentUnit::findOrFail($locked->equipment_unit_id);
            $previous = $locked->previous_unit_status ?? EquipmentUnit::STATUS_AVAILABLE;
            if ($unit->status === EquipmentUnit::STATUS_IN_REPAIR && $previous !== EquipmentUnit::STATUS_IN_REPAIR) {
                $this->units->changeStatus($unit, $previous, $user->id, fromMaintenance: true);
            }

            if ($locked->incident_report_id !== null && $locked->previous_report_status !== null) {
                $report = IncidentReport::findOrFail($locked->incident_report_id);
                if ($report->status === IncidentReport::STATUS_IN_REPAIR) {
                    // Deshacer la apertura: vuelve al estado previo aunque la máquina del informe no tenga esa arista.
                    $report->forceFill(['status' => $locked->previous_report_status])->save();
                }
            }

            $record->setRawAttributes($locked->getAttributes(), true);

            return $record;
        });
    }

    /**
     * @throws InvalidMaintenanceRecordStatusTransition
     */
    private function assertTransition(MaintenanceRecord $record, string $to): void
    {
        if (! MaintenanceRecordStateMachine::canTransition($record->status, $to)) {
            throw new InvalidMaintenanceRecordStatusTransition($record->status, $to);
        }
    }
}
