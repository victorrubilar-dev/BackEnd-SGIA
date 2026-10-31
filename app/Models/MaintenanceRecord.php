<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Registro de mantención de una unidad. Al finalizar recalcula las fechas de la unidad.
 */
class MaintenanceRecord extends Model
{
    use HasFactory;

    public const TYPE_PREVENTIVE = 'preventiva';

    public const TYPE_CORRECTIVE = 'correctiva';

    public const TYPE_CALIBRATION = 'calibracion';

    public const TYPES = [self::TYPE_PREVENTIVE, self::TYPE_CORRECTIVE, self::TYPE_CALIBRATION];

    public const MODE_OWN = 'medios_propios';

    public const MODE_EXTERNAL = 'externa';

    public const MODES = [self::MODE_OWN, self::MODE_EXTERNAL];

    public const STATUS_IN_PROGRESS = 'en_curso';

    public const STATUS_FINISHED = 'finalizada';

    public const STATUS_CANCELLED = 'anulada';

    public const STATUSES = [self::STATUS_IN_PROGRESS, self::STATUS_FINISHED, self::STATUS_CANCELLED];

    protected $attributes = [
        'execution_mode' => self::MODE_OWN,
        'status' => self::STATUS_IN_PROGRESS,
        'repair_cost' => 0,
    ];

    protected $fillable = [
        'equipment_unit_id', 'responsible_id', 'external_supplier_id', 'incident_report_id',
        'maintenance_type', 'execution_mode', 'rev_a_approved', 'rev_b_approved', 'rev_c_approved',
        'pfg_approved', 'requires_additional_maintenance', 'applies_recf', 'repair_cost',
        'spare_parts_used', 'technical_notes', 'status', 'started_at', 'finished_at',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'rev_a_approved' => 'boolean',
            'rev_b_approved' => 'boolean',
            'rev_c_approved' => 'boolean',
            'pfg_approved' => 'boolean',
            'requires_additional_maintenance' => 'boolean',
            'applies_recf' => 'boolean',
            'repair_cost' => 'decimal:2',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Regla de modelo: la ejecución externa exige proveedor externo.
     */
    public function hasValidExecution(): bool
    {
        return $this->execution_mode !== self::MODE_EXTERNAL || $this->external_supplier_id !== null;
    }

    /**
     * Finaliza la mantención y actualiza `last_maintenance_at` / `next_maintenance_at` de la unidad
     * según `products.maintenance_frequency_months` (sin frecuencia, no hay próxima fecha).
     */
    public function finish(?\DateTimeInterface $at = null): self
    {
        $finishedAt = $at ? Carbon::instance($at) : now();

        $this->forceFill(['status' => self::STATUS_FINISHED, 'finished_at' => $finishedAt])->save();

        $unit = $this->equipmentUnit()->with('product')->firstOrFail();
        $months = $unit->product?->maintenance_frequency_months;
        $unit->forceFill([
            'last_maintenance_at' => $finishedAt,
            'next_maintenance_at' => $months ? $finishedAt->copy()->addMonthsNoOverflow($months) : null,
        ])->save();

        return $this;
    }

    public function equipmentUnit(): BelongsTo
    {
        return $this->belongsTo(EquipmentUnit::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function externalSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'external_supplier_id');
    }

    public function incidentReport(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MaintenanceDocument::class);
    }
}
