<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotizacionItem extends Model
{
    use HasFactory;

    protected $table = 'cotizacion_items';
    public $timestamps = false;

    protected $fillable = [
        'cotizacion_id',
        'producto_id',
        'cantidad_solicitada',
        'precio_unitario_ofertado',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_solicitada' => 'integer',
            'precio_unitario_ofertado' => 'decimal:2',
        ];
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'cotizacion_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function product(): BelongsTo
    {
        return $this->producto();
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->producto?->nombre ?? ('Producto #' . $this->producto_id),
        );
    }

    protected function quantity(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cantidad_solicitada,
            set: fn ($value) => ['cantidad_solicitada' => (int) $value],
        );
    }

    protected function unitPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->precio_unitario_ofertado,
            set: fn ($value) => ['precio_unitario_ofertado' => $value],
        );
    }
}
