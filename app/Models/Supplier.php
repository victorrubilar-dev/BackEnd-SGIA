<?php

namespace App\Models;

use App\Rules\ChileanRut;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

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

    public function quotationSuppliers(): HasMany
    {
        return $this->hasMany(QuotationSupplier::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
