<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Grafico extends Model
{
    use HasFactory;

    protected $table = 'graficos';

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'plantilla_id',
        'titulo_personalizado',
        'tipo',
        'posicion',
        'entidad',
        'metrica',
        'agrupado_por',
        'lapso_tiempo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'posicion' => 'integer',
            'activo' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaGrafico::class, 'plantilla_id');
    }
}
