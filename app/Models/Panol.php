<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Panol extends Model
{
    use HasFactory;

    protected $table = 'panoles';
    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'codigo',
        'ubicacion_general',
    ];

    public function salas(): HasMany
    {
        return $this->hasMany(Sala::class, 'panol_id');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'panol_id');
    }

    public function prestamos(): HasMany
    {
        return $this->hasMany(Prestamo::class, 'panol_id');
    }
}
