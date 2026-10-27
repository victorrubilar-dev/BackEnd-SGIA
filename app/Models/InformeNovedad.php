<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InformeNovedad extends Model
{
    use HasFactory;

    protected $table = 'informes_novedades';

    public const CREATED_AT = 'fecha_reporte';
    public const UPDATED_AT = null;

    public const SEVERITY_LOW = 'leve';
    public const SEVERITY_MEDIUM = 'media';
    public const SEVERITY_CRITICAL = 'critica';

    public const SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_CRITICAL,
    ];

    public const STATUS_REPORTED = 'reportado';
    public const STATUS_IN_REVIEW = 'en_revision';
    public const STATUS_RESOLVED = 'resuelto';

    protected $fillable = [
        'usuario_id',
        'equipo_id',
        'tipo_solicitud',
        'descripcion',
        'foto_evidencia_url',
        'documento_adjunto_url',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_reporte' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->usuario();
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class, 'equipo_id');
    }

    public function registrosMantencion(): HasMany
    {
        return $this->hasMany(RegistroMantencion::class, 'informe_novedad_id');
    }

    public function severityLabel(): string
    {
        return match ($this->severity) {
            self::SEVERITY_LOW => 'Leve',
            self::SEVERITY_CRITICAL => 'Crítica',
            default => 'Media',
        };
    }

    protected function code(): Attribute
    {
        return Attribute::make(
            get: fn () => sprintf('NOV-%06d', $this->id),
        );
    }

    protected function reportedBy(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->usuario_id,
            set: fn ($value) => ['usuario_id' => $value],
        );
    }

    protected function productId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->equipo?->producto_id,
        );
    }

    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->tipo_solicitud ?? ('Novedad #' . $this->id),
            set: fn ($value) => ['tipo_solicitud' => $value],
        );
    }

    protected function description(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->descripcion,
            set: fn ($value) => ['descripcion' => $value],
        );
    }

    protected function severity(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->tipo_solicitud ?? self::SEVERITY_MEDIUM,
            set: fn ($value) => ['tipo_solicitud' => $value],
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->estado,
            set: fn ($value) => ['estado' => $value],
        );
    }

    protected function attachment(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->documento_adjunto_url ?? $this->foto_evidencia_url,
            set: fn ($value) => ['documento_adjunto_url' => $value],
        );
    }

    protected function attachmentOriginalName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->documento_adjunto_url ? basename($this->documento_adjunto_url) : null,
        );
    }

    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fecha_reporte,
        );
    }

    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fecha_reporte,
        );
    }
}
