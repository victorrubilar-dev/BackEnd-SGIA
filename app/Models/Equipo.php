<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipo extends Model
{
    use HasFactory;

    protected $table = 'equipos';

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    protected $fillable = [
        'producto_id',
        'estante_id',
        'codigo_barras',
        'numero_serie',
        'estado',
        'fecha_ultima_mantencion',
        'fecha_proxima_mantencion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ultima_mantencion' => 'datetime',
            'fecha_proxima_mantencion' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function estante(): BelongsTo
    {
        return $this->belongsTo(Estante::class, 'estante_id');
    }

    public function informesNovedades(): HasMany
    {
        return $this->hasMany(InformeNovedad::class, 'equipo_id');
    }

    public function registrosMantencion(): HasMany
    {
        return $this->hasMany(RegistroMantencion::class, 'equipo_id');
    }

    public function documentosMantenimiento(): HasMany
    {
        return $this->hasMany(DocumentoMantenimiento::class, 'equipo_id');
    }

    public function prestamoDetalles(): HasMany
    {
        return $this->hasMany(PrestamoDetalle::class, 'equipo_id');
    }
}
