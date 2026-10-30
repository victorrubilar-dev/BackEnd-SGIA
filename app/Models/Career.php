<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Carrera y su nivel. Los usuarios la referencian por nombre en `users.area`.
 */
class Career extends Model
{
    use HasFactory;

    public const LEVEL_TECHNICIAN = 'tecnico';

    public const LEVEL_PROFESSIONAL = 'profesional';

    /** @var array<string, string> */
    public const LEVELS = [
        self::LEVEL_TECHNICIAN => 'Técnico de Nivel Superior',
        self::LEVEL_PROFESSIONAL => 'Profesional',
    ];

    protected $fillable = [
        'name',
        'level',
        'created_by',
        'updated_by',
    ];

    public function levelLabel(): string
    {
        return self::LEVELS[$this->level] ?? $this->level;
    }
}
