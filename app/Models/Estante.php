<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estante extends Model
{
    use HasFactory;

    protected $table = 'estantes';
    public $timestamps = false;

    protected $fillable = [
        'sala_id',
        'nombre',
        'codigo_barras',
    ];

    public function sala(): BelongsTo
    {
        return $this->belongsTo(Sala::class, 'sala_id');
    }

    public function location(): BelongsTo
    {
        return $this->sala();
    }

    public function equipos(): HasMany
    {
        return $this->hasMany(Equipo::class, 'estante_id');
    }

    /**
     * Accesor para compatibilidad con código que espera $estante->codigo.
     */
    protected function codigo(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->codigo_barras ?? $this->nombre,
            set: fn ($value) => ['codigo_barras' => $value],
        );
    }
}
