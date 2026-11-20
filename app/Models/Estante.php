<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Mueble del plano de una sala (REQ-22): agrupa cajones y tiene posición y tamaño en centímetros.
 * No es el `estantes` de `MODELS.MD` (ese es `cajones`).
 */
class Estante extends Model
{
    use HasFactory;

    protected $table = 'estantes';

    /** Largo máximo del código normalizado (`EST-A1`). */
    public const CODE_MAX_LENGTH = 12;

    public const MIN_SIZE = 20;

    public const MAX_SIZE = 2000;

    public const ROTATIONS = [0, 90];

    protected $fillable = [
        'location_id',
        'zona_id',
        'codigo',
        'nombre',
        'descripcion',
        'x',
        'y',
        'width',
        'depth',
        'rotation',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (Estante $estante) {
            $estante->codigo = Cajon::normalizeCode((string) $estante->codigo);
        });
    }

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'width' => 'integer',
            'depth' => 'integer',
            'rotation' => 'integer',
        ];
    }

    /**
     * Cajones del estante para el plano: por código, con insumos activos (`products_count`) y unidades
     * que no están dadas de baja (`units_count`). Los equipos se cuentan por unidades, no por producto.
     *
     * @return array<string, \Closure>
     */
    public static function mapRelations(): array
    {
        return ['cajones' => fn (Relation $query) => $query->orderBy('codigo')->withCount([
            'products as products_count' => fn (Builder $q) => $q->where('type', Product::TYPE_SUPPLY)->where('is_active', true),
            'equipmentUnits as units_count' => fn (Builder $q) => $q->where('status', '!=', EquipmentUnit::STATUS_WRITTEN_OFF),
        ])];
    }

    /**
     * `has_critical_stock`: algún insumo activo del estante en o bajo su stock mínimo.
     *
     * @return array<string, \Closure>
     */
    public static function criticalStockExists(): array
    {
        return ['cajones as has_critical_stock' => fn (Builder $query) => $query->whereHas('products', fn (Builder $q) => $q
            ->where('type', Product::TYPE_SUPPLY)
            ->where('is_active', true)
            ->whereColumn('quantity', '<=', 'stock_minimo'))];
    }

    /** Carga lo que necesita `EstanteResource` (cajones resumidos y stock crítico). */
    public function loadMapSummary(): static
    {
        return $this->load(static::mapRelations())->loadExists(static::criticalStockExists());
    }

    /** Ancho ocupado en el plano (con la rotación aplicada). */
    public function occupiedWidth(): int
    {
        return $this->rotation === 90 ? $this->depth : $this->width;
    }

    /** Alto ocupado en el plano (con la rotación aplicada). */
    public function occupiedHeight(): int
    {
        return $this->rotation === 90 ? $this->width : $this->depth;
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Zona, $this> */
    public function zona(): BelongsTo
    {
        return $this->belongsTo(Zona::class);
    }

    /** @return HasMany<Cajon, $this> */
    public function cajones(): HasMany
    {
        return $this->hasMany(Cajon::class);
    }
}
