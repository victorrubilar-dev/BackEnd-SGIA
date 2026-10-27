<?php

namespace App\Models;

use App\Services\LoanStateMachine;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prestamo extends Model
{
    use HasFactory;

    protected $table = 'prestamos';

    public const CREATED_AT = 'fecha_solicitud';
    public const UPDATED_AT = null;

    public const TYPE_REMOTE = 'remoto';
    public const TYPE_DIRECT = 'presencial';

    protected $fillable = [
        'panol_id',
        'tipo_prestamo',
        'estudiante_id',
        'profesor_id',
        'despachado_por_id',
        'recibido_por_id',
        'estado',
        'fecha_solicitud',
        'fecha_hora_prestamo',
        'fecha_hora_devolucion_prevista',
        'fecha_hora_devolucion_real',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_solicitud' => 'datetime',
            'fecha_hora_prestamo' => 'datetime',
            'fecha_hora_devolucion_prevista' => 'datetime',
            'fecha_hora_devolucion_real' => 'datetime',
        ];
    }

    public function panol(): BelongsTo
    {
        return $this->belongsTo(Panol::class, 'panol_id');
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class, 'estudiante_id');
    }

    public function profesor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'profesor_id');
    }

    public function despachadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'despachado_por_id');
    }

    public function recibidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'recibido_por_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(PrestamoDetalle::class, 'prestamo_id');
    }

    public function items(): HasMany
    {
        return $this->detalles();
    }

    public function requester(): BelongsTo
    {
        return $this->profesor();
    }

    // Helper methods for state inspection
    public function isPending(): bool
    {
        return strtolower((string) $this->estado) === LoanStateMachine::STATUS_PENDING;
    }

    public function isInProcess(): bool
    {
        return strtolower((string) $this->estado) === LoanStateMachine::STATUS_IN_PROGRESS;
    }

    public function isProcessed(): bool
    {
        return strtolower((string) $this->estado) === LoanStateMachine::STATUS_PROCESSED;
    }

    public function isRejected(): bool
    {
        return strtolower((string) $this->estado) === LoanStateMachine::STATUS_REJECTED;
    }

    public function getMetadata(): array
    {
        if (empty($this->observaciones)) {
            return [];
        }
        $decoded = json_decode($this->observaciones, true);
        return is_array($decoded) ? $decoded : ['notes' => $this->observaciones];
    }

    public function setMetadataValue(string $key, mixed $value): void
    {
        $meta = $this->getMetadata();
        $meta[$key] = $value;
        $this->observaciones = json_encode($meta, JSON_UNESCAPED_UNICODE);
    }

    // Accessors for backward compatibility
    protected function code(): Attribute
    {
        return Attribute::make(
            get: fn () => sprintf('PRE-%06d', $this->id),
        );
    }

    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn () => strtolower((string) $this->tipo_prestamo),
            set: fn ($value) => ['tipo_prestamo' => (string) $value],
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => strtolower((string) $this->estado),
            set: fn ($value) => ['estado' => (string) $value],
        );
    }

    protected function requestedBy(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->profesor_id,
            set: fn ($value) => ['profesor_id' => $value],
        );
    }

    protected function borrowerName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getMetadata()['borrower_name'] ?? $this->profesor?->nombre ?? $this->estudiante?->nombre_completo ?? 'Docente',
        );
    }

    protected function borrowerDocument(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getMetadata()['borrower_document'] ?? $this->estudiante?->rut ?? null,
        );
    }

    protected function subject(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getMetadata()['subject'] ?? null,
        );
    }

    protected function room(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getMetadata()['room'] ?? null,
        );
    }

    protected function timeBlock(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getMetadata()['time_block'] ?? null,
        );
    }

    protected function rejectionReason(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getMetadata()['rejection_reason'] ?? null,
        );
    }

    protected function notes(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getMetadata()['notes'] ?? (is_string($this->observaciones) && !str_starts_with($this->observaciones, '{') ? $this->observaciones : null),
        );
    }

    protected function approvedBy(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->despachado_por_id,
        );
    }

    protected function approvedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fecha_hora_prestamo,
        );
    }

    protected function processedBy(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->recibido_por_id ?? $this->despachado_por_id,
        );
    }

    protected function processedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fecha_hora_prestamo ?? $this->fecha_hora_devolucion_real,
        );
    }

    protected function loanDate(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fecha_solicitud ?? $this->fecha_hora_prestamo ?? now(),
        );
    }

    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fecha_solicitud,
        );
    }

    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fecha_solicitud,
        );
    }
}
