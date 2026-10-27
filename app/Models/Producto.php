<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Producto extends Model
{
    use HasFactory;

    protected $table = 'productos';

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    protected $fillable = [
        'categoria_id',
        'carrera_id',
        'sku',
        'nombre',
        'tipo',
        'prioridad_mantencion',
        'requiere_calibracion',
        'frecuencia_mantencion_meses',
        'precio_referencia',
        'foto_url',
        'stock_actual',
        'stock_minimo',
    ];

    protected function casts(): array
    {
        return [
            'requiere_calibracion' => 'boolean',
            'frecuencia_mantencion_meses' => 'integer',
            'precio_referencia' => 'decimal:2',
            'stock_actual' => 'integer',
            'stock_minimo' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class, 'carrera_id');
    }

    public function equipos(): HasMany
    {
        return $this->hasMany(Equipo::class, 'producto_id');
    }

    public function ingresoOcrDetalles(): HasMany
    {
        return $this->hasMany(IngresoOcrDetalle::class, 'producto_id');
    }

    public function cotizacionItems(): HasMany
    {
        return $this->hasMany(CotizacionItem::class, 'producto_id');
    }

    public function incidentReports(): HasManyThrough
    {
        return $this->hasManyThrough(InformeNovedad::class, Equipo::class, 'producto_id', 'equipo_id');
    }

    public function getEstanteAttribute(): ?Estante
    {
        $this->loadMissing('equipos.estante');
        return $this->equipos->first()?->estante;
    }

    public function getCajonAttribute(): ?Estante
    {
        return $this->estante;
    }

    public function getSalaAttribute(): ?Sala
    {
        $this->loadMissing('equipos.estante.sala');
        return $this->equipos->first()?->estante?->sala;
    }

    public function getLocationAttribute(): ?Sala
    {
        return $this->sala;
    }

    public function getSupplierAttribute(): ?Proveedor
    {
        return null;
    }

    /**
     * Accesor para compatibilidad con código o front que consulta $producto->name.
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->nombre,
        );
    }

    /**
     * Accesor para compatibilidad con código o front que consulta $producto->quantity.
     */
    protected function quantity(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) $this->stock_actual,
        );
    }

    /**
     * Accesor para compatibilidad con código o front que consulta $producto->barcode.
     */
    protected function barcode(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->sku ?? $this->equipos->first()?->codigo_barras,
        );
    }

    /**
     * Accesor para compatibilidad con código o front que consulta $producto->is_active.
     */
    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn () => true,
        );
    }

    public function isCriticalStock(): bool
    {
        return (int) $this->stock_actual <= (int) $this->stock_minimo;
    }

    public function isWarningStock(): bool
    {
        $actual = (int) $this->stock_actual;
        $min = (int) $this->stock_minimo;

        return $actual > $min && $actual <= ($min + 5);
    }
}
