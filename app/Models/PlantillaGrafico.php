<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlantillaGrafico extends Model
{
    use HasFactory;

    protected $table = 'plantillas_grafico';
    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo_visualizacion',
        'descripcion',
    ];

    public function graficos(): HasMany
    {
        return $this->hasMany(Grafico::class, 'plantilla_id');
    }
}
