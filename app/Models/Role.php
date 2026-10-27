<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $table = 'roles';
    public $timestamps = false;

    public const ADMIN = 'Administrador';
    public const DIRECTOR = 'Director de carrera';
    public const PANOL = 'Pañol';
    public const AYUDANTE_PANOL = 'Ayudante de pañol';
    public const DOCENTE = 'Docente';

    public const ROLES = [
        self::ADMIN,
        self::DIRECTOR,
        self::PANOL,
        self::AYUDANTE_PANOL,
        self::DOCENTE,
    ];

    protected $fillable = ['nombre'];

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'rol_id');
    }
}
