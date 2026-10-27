<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcesoCotizacion extends Model
{
    use HasFactory;

    protected $table = 'procesos_cotizacion';

    public const CREATED_AT = 'fecha_creacion';
    public const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'carrera_id',
        'titulo_proceso',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_creacion' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function creator(): BelongsTo
    {
        return $this->usuario();
    }

    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class, 'carrera_id');
    }

    public function cotizaciones(): HasMany
    {
        return $this->hasMany(Cotizacion::class, 'proceso_cotizacion_id');
    }

    public function suppliers(): HasMany
    {
        return $this->cotizaciones();
    }

    public function getItemsAttribute()
    {
        $this->loadMissing('cotizaciones.items.producto');
        $first = $this->cotizaciones->first();
        return $first ? $first->items : collect();
    }

    public function getSupplierAttribute(): ?Proveedor
    {
        $this->loadMissing('cotizaciones.proveedor');
        $accepted = $this->cotizaciones->first(fn ($c) => strtolower((string) $c->estado) === 'aceptada' || strtolower((string) $c->estado) === 'adjudicada');
        return ($accepted ?? $this->cotizaciones->first())?->proveedor;
    }

    public function getSupplierIdAttribute(): ?int
    {
        return $this->supplier?->id;
    }

    public function getTotalAttribute(): ?float
    {
        $this->loadMissing('cotizaciones');
        $accepted = $this->cotizaciones->first(fn ($c) => strtolower((string) $c->estado) === 'aceptada' || strtolower((string) $c->estado) === 'adjudicada');
        $total = ($accepted ?? $this->cotizaciones->first())?->monto_total;
        return $total !== null ? (float) $total : null;
    }

    protected function code(): Attribute
    {
        return Attribute::make(
            get: fn () => sprintf('COT-%06d', $this->id),
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => strtolower((string) $this->estado),
            set: fn ($value) => ['estado' => (string) $value],
        );
    }

    protected function createdBy(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->usuario_id,
            set: fn ($value) => ['usuario_id' => $value],
        );
    }

    protected function notes(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->titulo_proceso,
            set: fn ($value) => ['titulo_proceso' => $value],
        );
    }

    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fecha_creacion,
        );
    }

    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fecha_creacion,
        );
    }
}
