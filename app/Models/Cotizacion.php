<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cotizacion extends Model
{
    use HasFactory;

    protected $table = 'cotizaciones';

    public const CREATED_AT = 'fecha_envio';
    public const UPDATED_AT = null;

    protected $fillable = [
        'proceso_cotizacion_id',
        'proveedor_id',
        'numero_cotizacion',
        'archivo_respaldo_url',
        'monto_total',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'monto_total' => 'decimal:2',
            'fecha_envio' => 'datetime',
        ];
    }

    public function procesoCotizacion(): BelongsTo
    {
        return $this->belongsTo(ProcesoCotizacion::class, 'proceso_cotizacion_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->proveedor();
    }

    public function items(): HasMany
    {
        return $this->hasMany(CotizacionItem::class, 'cotizacion_id');
    }

    protected function quotationId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->proceso_cotizacion_id,
        );
    }

    protected function supplierId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->proveedor_id,
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => strtolower((string) $this->estado),
            set: fn ($value) => ['estado' => (string) $value],
        );
    }

    protected function offerTotal(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->monto_total,
            set: fn ($value) => ['monto_total' => $value],
        );
    }

    protected function notes(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->numero_cotizacion,
            set: fn ($value) => ['numero_cotizacion' => $value],
        );
    }

    protected function respondedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fecha_envio,
        );
    }

    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fecha_envio,
        );
    }
}
