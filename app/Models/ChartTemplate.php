<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plantilla de gráfico (REQ-15). `chart_type`, `entity`, `metric`, `group_by` y `period` son la
 * configuración por defecto que hereda un gráfico de usuario (nulos en las plantillas genéricas).
 */
class ChartTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'visualization_type', 'chart_type', 'entity', 'metric', 'group_by', 'period', 'description'];

    public function charts(): HasMany
    {
        return $this->hasMany(UserChart::class);
    }
}
