<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    use HasFactory;

    protected $table = 'proveedores';
    public $timestamps = false;

    protected $fillable = [
        'rut',
        'razon_social',
        'nombre_contacto',
        'email',
        'telefono',
        'direccion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function cotizaciones(): HasMany
    {
        return $this->hasMany(Cotizacion::class, 'proveedor_id');
    }

    public function registrosMantencion(): HasMany
    {
        return $this->hasMany(RegistroMantencion::class, 'proveedor_externo_id');
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->razon_social,
            set: fn ($value) => ['razon_social' => $value],
        );
    }

    protected function contactName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->nombre_contacto,
            set: fn ($value) => ['nombre_contacto' => $value],
        );
    }

    protected function phone(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->telefono,
            set: fn ($value) => ['telefono' => $value],
        );
    }

    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) $this->activo,
            set: fn ($value) => ['activo' => (bool) $value],
        );
    }
}
