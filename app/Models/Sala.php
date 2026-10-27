<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sala extends Model
{
    use HasFactory;

    protected $table = 'salas';
    public $timestamps = false;

    protected $fillable = [
        'panol_id',
        'nombre_codigo',
        'edificio',
        'piso',
    ];

    protected function casts(): array
    {
        return [
            'piso' => 'integer',
        ];
    }

    public function panol(): BelongsTo
    {
        return $this->belongsTo(Panol::class, 'panol_id');
    }

    public function estantes(): HasMany
    {
        return $this->hasMany(Estante::class, 'sala_id');
    }

    /**
     * Accesor para compatibilidad con código que espera $sala->nombre.
     */
    protected function nombre(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->nombre_codigo,
            set: fn ($value) => ['nombre_codigo' => $value],
        );
    }

    /**
     * Accesor para compatibilidad con código que espera $sala->cajones.
     */
    public function cajones(): HasMany
    {
        return $this->estantes();
    }
}
