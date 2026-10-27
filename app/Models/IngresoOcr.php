<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngresoOcr extends Model
{
    use HasFactory;

    protected $table = 'ingresos_ocr';

    public const CREATED_AT = 'fecha_ingreso';
    public const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'presupuesto_anual_id',
        'archivo_pdf_url',
        'numero_documento',
        'monto_total_descontado',
        'raw_ocr_payload',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'monto_total_descontado' => 'decimal:2',
            'raw_ocr_payload' => 'array',
            'fecha_ingreso' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function presupuestoAnual(): BelongsTo
    {
        return $this->belongsTo(PresupuestoAnual::class, 'presupuesto_anual_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(IngresoOcrDetalle::class, 'ingreso_ocr_id');
    }
}
