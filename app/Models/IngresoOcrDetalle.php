<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngresoOcrDetalle extends Model
{
    use HasFactory;

    protected $table = 'ingreso_ocr_detalles';
    public $timestamps = false;

    protected $fillable = [
        'ingreso_ocr_id',
        'producto_id',
        'nombre_detectado',
        'cantidad',
        'precio_unitario',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'precio_unitario' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function ingresoOcr(): BelongsTo
    {
        return $this->belongsTo(IngresoOcr::class, 'ingreso_ocr_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
