<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carrera extends Model
{
    use HasFactory;

    protected $table = 'carreras';
    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'nombre',
    ];

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'carrera_usuario', 'carrera_id', 'usuario_id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'carrera_id');
    }

    public function presupuestosAnuales(): HasMany
    {
        return $this->hasMany(PresupuestoAnual::class, 'carrera_id');
    }

    public function procesosCotizacion(): HasMany
    {
        return $this->hasMany(ProcesoCotizacion::class, 'carrera_id');
    }

    public function estudiantes(): HasMany
    {
        return $this->hasMany(Estudiante::class, 'carrera_id');
    }
}
