<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'sala',
        'cajon',
        'descripcion',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
