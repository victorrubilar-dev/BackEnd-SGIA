<?php

namespace App\Models;

use App\Enums\SupplierArea;
use App\Rules\ChileanRut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    /** Las áreas viajan siempre con el proveedor (`SupplierResource::areas`). */
    protected $with = ['areas'];

    protected $fillable = [
        'name',
        'contact_name',
        'email',
        'phone',
        'rut',
        'address',
        'category',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected function rut(): Attribute
    {
        return Attribute::set(fn (?string $value) => filled($value) ? ChileanRut::normalize($value) : null);
    }

    /**
     * @return HasMany<SupplierAreaAssignment, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(SupplierAreaAssignment::class);
    }

    /**
     * Valores de las áreas del proveedor, ordenados.
     *
     * @return array<int, string>
     */
    public function areaValues(): array
    {
        return $this->areas->map(fn (SupplierAreaAssignment $a) => $a->area->value)->sort()->values()->all();
    }

    /**
     * Reemplaza las áreas del proveedor por `$areas` (valores de `SupplierArea`).
     *
     * @param  array<int, string>  $areas
     */
    public function syncAreas(array $areas): void
    {
        $areas = array_values(array_unique($areas));

        $this->areas()->whereNotIn('area', $areas)->delete();

        foreach ($areas as $area) {
            $this->areas()->firstOrCreate(['area' => $area]);
        }

        $this->unsetRelation('areas');
        $this->load('areas');
    }

    /**
     * Proveedores con al menos una de las áreas indicadas.
     *
     * @param  array<int, string|SupplierArea>  $areas
     */
    public function scopeInAreas(Builder $query, array $areas): Builder
    {
        $values = array_map(fn ($a) => $a instanceof SupplierArea ? $a->value : $a, $areas);

        return $query->whereHas('areas', fn (Builder $q) => $q->whereIn('area', $values));
    }

    public function quotationSuppliers(): HasMany
    {
        return $this->hasMany(QuotationSupplier::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
