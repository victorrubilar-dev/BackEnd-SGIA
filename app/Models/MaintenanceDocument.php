<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento de mantenimiento de una unidad (REQ-19): PDF en disco privado `local`.
 */
class MaintenanceDocument extends Model
{
    use HasFactory;

    public const TYPE_CALIBRATION_CERTIFICATE = 'certificado_calibracion';

    public const TYPE_TECHNICAL_REPORT = 'informe_tecnico';

    public const TYPE_MANUAL = 'manual';

    public const TYPE_WARRANTY = 'garantia';

    public const TYPE_OTHER = 'otro';

    public const TYPES = [
        self::TYPE_CALIBRATION_CERTIFICATE,
        self::TYPE_TECHNICAL_REPORT,
        self::TYPE_MANUAL,
        self::TYPE_WARRANTY,
        self::TYPE_OTHER,
    ];

    /** Carpeta del disco `local`. */
    public const DIRECTORY = 'maintenance-documents';

    protected $fillable = [
        'equipment_unit_id', 'maintenance_record_id', 'uploaded_by',
        'document_type', 'title', 'file_path', 'original_name', 'size_bytes', 'valid_until',
    ];

    protected function casts(): array
    {
        return ['valid_until' => 'datetime', 'size_bytes' => 'integer'];
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /**
     * Sin vencimiento o con vencimiento futuro.
     *
     * @param  Builder<MaintenanceDocument>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()));
    }

    public function equipmentUnit(): BelongsTo
    {
        return $this->belongsTo(EquipmentUnit::class);
    }

    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
