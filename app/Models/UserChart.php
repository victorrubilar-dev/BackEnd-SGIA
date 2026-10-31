<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gráfico del dashboard personalizado de un usuario (máximo 5 posiciones).
 */
class UserChart extends Model
{
    use HasFactory;

    public const MAX_POSITION = 5;

    protected $fillable = [
        'user_id', 'chart_template_id', 'custom_title', 'type', 'position',
        'entity', 'metric', 'group_by', 'period', 'is_active',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChartTemplate::class, 'chart_template_id');
    }
}
