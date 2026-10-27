<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoMantenimiento extends Model
{
    use HasFactory;

    protected $table = 'documentos_mantenimiento';
    public $timestamps = false;

    protected $fillable = [
        'equipo_id',
        'registro_mantencion_id',
        'subido_por_id',
        'tipo_documento',
        'titulo',
        'archivo_pdf_url',
        'fecha_vigencia',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vigencia' => 'datetime',
        ];
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class, 'equipo_id');
    }

    public function registroMantencion(): BelongsTo
    {
        return $this->belongsTo(RegistroMantencion::class, 'registro_mantencion_id');
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'subido_por_id');
    }
}
