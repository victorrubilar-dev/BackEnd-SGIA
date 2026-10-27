<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estudiante extends Model
{
    use HasFactory;

    protected $table = 'estudiantes';

    public const CREATED_AT = 'fecha_registro';
    public const UPDATED_AT = null;

    protected $fillable = [
        'rut',
        'nombre_completo',
        'email',
        'carrera_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_registro' => 'datetime',
        ];
    }

    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class, 'carrera_id');
    }

    public function prestamos(): HasMany
    {
        return $this->hasMany(Prestamo::class, 'estudiante_id');
    }
}
