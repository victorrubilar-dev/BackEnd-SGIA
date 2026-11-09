<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class IncidentReport extends Model
{
    use HasFactory;

    public const REQUEST_FAILURE = 'falla';

    public const REQUEST_REPLACEMENT = 'reposicion';

    public const REQUEST_MAINTENANCE = 'mantencion';

    public const REQUEST_TYPES = [self::REQUEST_FAILURE, self::REQUEST_REPLACEMENT, self::REQUEST_MAINTENANCE];

    public const SEVERITY_LOW = 'leve';

    public const SEVERITY_MEDIUM = 'media';

    public const SEVERITY_HIGH = 'critica';

    public const SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
    ];

    public const STATUS_REPORTED = 'reportado';

    public const STATUS_IN_REVIEW = 'en_revision';

    public const STATUS_IN_REPAIR = 'en_reparacion';

    public const STATUS_REPAIRED = 'reparado';

    public const STATUS_DISCARDED = 'dado_de_baja';

    // Estados finales del formulario del docente.
    public const STATUS_RESOLVED = 'resuelto';

    public const STATUS_DISMISSED = 'descartado';

    public const PROBLEM_EQUIPMENT_FAILURE = 'falla_equipo';

    public const PROBLEM_MISSING_EQUIPMENT = 'falta_equipamiento';

    public const PROBLEM_MISSING_SUPPLIES = 'falta_insumo';

    public const PROBLEM_OTHER = 'otros';

    /** Tipos de ubicación donde se puede reportar: salas, talleres y pañoles (no bodegas). */
    public const LOCATION_TYPES = [Location::TIPO_SALA, Location::TIPO_TALLER, Location::TIPO_PANOL];

    /** Casillas del formulario: falla de equipos / instrumento / software, falta de equipamiento, falta de insumo, otros. */
    public const PROBLEM_LABELS = [
        self::PROBLEM_EQUIPMENT_FAILURE => 'Falla de equipos / instrumento / software',
        self::PROBLEM_MISSING_EQUIPMENT => 'Falta de equipamiento',
        self::PROBLEM_MISSING_SUPPLIES => 'Falta de insumo',
        self::PROBLEM_OTHER => 'Otros',
    ];

    /** Estados finales (ambos formatos). */
    public const FINAL_STATUSES = [
        self::STATUS_REPAIRED,
        self::STATUS_DISCARDED,
        self::STATUS_RESOLVED,
        self::STATUS_DISMISSED,
    ];

    public const STATUSES = [
        self::STATUS_REPORTED,
        self::STATUS_IN_REVIEW,
        self::STATUS_IN_REPAIR,
        self::STATUS_REPAIRED,
        self::STATUS_DISCARDED,
        self::STATUS_RESOLVED,
        self::STATUS_DISMISSED,
    ];

    /**
     * Transiciones de los informes anteriores (por equipo); `reparado` y `dado_de_baja` son finales.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_REPORTED => [self::STATUS_IN_REVIEW, self::STATUS_IN_REPAIR, self::STATUS_DISCARDED],
        self::STATUS_IN_REVIEW => [self::STATUS_IN_REPAIR, self::STATUS_REPAIRED, self::STATUS_DISCARDED],
        self::STATUS_IN_REPAIR => [self::STATUS_REPAIRED, self::STATUS_DISCARDED],
        self::STATUS_REPAIRED => [],
        self::STATUS_DISCARDED => [],
    ];

    /**
     * Transiciones de los informes del formulario del docente. `en_reparacion` solo lo fija
     * una mantención abierta desde el informe.
     *
     * @var array<string, array<int, string>>
     */
    public const FORM_TRANSITIONS = [
        self::STATUS_REPORTED => [self::STATUS_IN_REVIEW, self::STATUS_RESOLVED, self::STATUS_DISMISSED],
        self::STATUS_IN_REVIEW => [self::STATUS_RESOLVED, self::STATUS_DISMISSED],
        self::STATUS_IN_REPAIR => [self::STATUS_RESOLVED, self::STATUS_DISMISSED],
        self::STATUS_REPAIRED => [],
        self::STATUS_DISCARDED => [],
        self::STATUS_RESOLVED => [],
        self::STATUS_DISMISSED => [],
    ];

    public const SEVERITY_LABELS = [
        self::SEVERITY_LOW => 'Leve',
        self::SEVERITY_MEDIUM => 'Media',
        self::SEVERITY_HIGH => 'Crítica',
    ];

    protected $attributes = [
        'request_type' => self::REQUEST_FAILURE,
    ];

    protected $fillable = [
        'product_id',
        'equipment_unit_id',
        'request_type',
        'evidence_photo_path',
        'code',
        'reported_by',
        'title',
        'description',
        'severity',
        'status',
        'attachment',
        'attachment_original_name',
        'teacher_name',
        'subject',
        'report_date',
        'location_id',
        'problems',
        'suggestion',
        'signature_path',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'problems' => 'array',
            'signed_at' => 'datetime',
        ];
    }

    /** Informe anterior al formulario del docente (por equipo, sin problemas marcados). */
    public function isLegacy(): bool
    {
        return $this->problems === null;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function equipmentUnit(): BelongsTo
    {
        return $this->belongsTo(EquipmentUnit::class);
    }

    /**
     * @return array<int, string>
     */
    public function allowedTransitions(): array
    {
        $map = $this->isLegacy() ? self::TRANSITIONS : self::FORM_TRANSITIONS;

        return $map[$this->status] ?? [];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function severityLabel(): string
    {
        return self::SEVERITY_LABELS[$this->severity] ?? $this->severity;
    }

    /**
     * Generate a unique report code (e.g. NV-2026-A1B2C3).
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = 'NV-'.date('Y').'-'.strtoupper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
