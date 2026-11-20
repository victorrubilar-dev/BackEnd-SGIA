<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
