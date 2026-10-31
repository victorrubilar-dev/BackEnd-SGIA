<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'visualization_type', 'description'];

    public function charts(): HasMany
    {
        return $this->hasMany(UserChart::class);
    }
}
