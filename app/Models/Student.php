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

    protected function casts(): array
    {
        return ['registered_at' => 'datetime'];
    }

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
