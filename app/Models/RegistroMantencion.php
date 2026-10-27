<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegistroMantencion extends Model
{
    use HasFactory;

    protected $table = 'registros_mantencion';

    public const CREATED_AT = 'fecha_inicio';
    public const UPDATED_AT = null;

    protected $fillable = [
        'equipo_id',
        'responsable_id',
        'proveedor_externo_id',
        'informe_novedad_id',
        'tipo_mantencion',
        'modalidad_ejecucion',
        'rev_a_aprobada',
        'rev_b_aprobada',
        'rev_c_aprobada',
        'pfg_aprobada',
        'requiere_mantencion_adicional',
        'aplica_recf',
        'costo_reparacion',
        'repuestos_utilizados',
        'observaciones_tecnicas',
        'estado',
        'fecha_termino',
    ];

    protected function casts(): array
    {
        return [
            'rev_a_aprobada' => 'boolean',
            'rev_b_aprobada' => 'boolean',
            'rev_c_aprobada' => 'boolean',
            'pfg_aprobada' => 'boolean',
            'requiere_mantencion_adicional' => 'boolean',
            'aplica_recf' => 'boolean',
            'costo_reparacion' => 'decimal:2',
            'fecha_inicio' => 'datetime',
            'fecha_termino' => 'datetime',
        ];
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class, 'equipo_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsable_id');
    }

    public function proveedorExterno(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_externo_id');
    }

    public function informeNovedad(): BelongsTo
    {
        return $this->belongsTo(InformeNovedad::class, 'informe_novedad_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoMantenimiento::class, 'registro_mantencion_id');
    }
}
