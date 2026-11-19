<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasFactory;

    protected $fillable = ['rut', 'full_name', 'email', 'career_id', 'registered_at'];

    // Igual que el default de la columna: un estudiante recién creado ya viene activo.
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Career, $this> */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    /** @return HasMany<Loan, $this> */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
