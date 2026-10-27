<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class PrestamoDetalle extends Model
{
    use HasFactory;

    protected $table = 'prestamo_detalles';
    public $timestamps = false;

    protected $fillable = [
        'prestamo_id',
        'equipo_id',
        'cantidad_solicitada',
        'cantidad_entregada',
        'cantidad_devuelta',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_solicitada' => 'integer',
            'cantidad_entregada' => 'integer',
            'cantidad_devuelta' => 'integer',
        ];
    }

    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class, 'prestamo_id');
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class, 'equipo_id');
    }

    public function product(): HasOneThrough
    {
        return $this->hasOneThrough(Producto::class, Equipo::class, 'id', 'id', 'equipo_id', 'producto_id');
    }

    protected function productId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->equipo?->producto_id,
        );
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->equipo?->producto?->nombre ?? ('Equipo #' . $this->equipo_id),
        );
    }

    protected function quantity(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cantidad_solicitada,
            set: fn ($value) => ['cantidad_solicitada' => (int) $value],
        );
    }

    protected function quantityDelivered(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cantidad_entregada,
            set: fn ($value) => ['cantidad_entregada' => (int) $value],
        );
    }
}
