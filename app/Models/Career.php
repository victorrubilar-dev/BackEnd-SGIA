<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** Códigos válidos: segmento CARRERA de los códigos de barra. */
    public const CODES = ['ISD', 'II', 'IC', 'AP'];

    protected $fillable = [
        'name',
        'code',
        'level',
        'created_by',
        'updated_by',
    ];

    public function levelLabel(): string
    {
        return self::LEVELS[$this->level] ?? $this->level;
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function annualBudgets(): HasMany
    {
        return $this->hasMany(AnnualBudget::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
