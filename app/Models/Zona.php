<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Área con nombre dentro del plano de una sala (REQ-22). Puede contener estantes y superponerse a otras zonas.
 */
class Zona extends Model
{
    use HasFactory;

    protected $table = 'zonas';

    public const NOMBRE_MAX_LENGTH = 60;

    public const MIN_SIZE = 50;

    public const MAX_SIZE = 10000;

    protected $fillable = [
        'location_id',
        'nombre',
        'x',
        'y',
        'width',
        'depth',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'width' => 'integer',
            'depth' => 'integer',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<Estante, $this> */
    public function estantes(): HasMany
    {
        return $this->hasMany(Estante::class);
    }
}
