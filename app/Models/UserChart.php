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
        'entity', 'metric', 'group_by', 'period', 'date_from', 'date_to', 'is_active',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_active' => 'boolean', 'date_from' => 'date', 'date_to' => 'date'];
    }

    /**
     * Variables de `customMetric` (`MetricQueryInput`) para `POST /api/metrics`.
     *
     * @return array<string, mixed>
     */
    public function metricInput(): array
    {
        return array_filter([
            'entity' => $this->entity,
            'metric' => $this->metric,
            'timeRange' => $this->period,
            'chartType' => $this->type,
            'title' => $this->custom_title,
            'groupBy' => $this->group_by,
            'dateFrom' => $this->date_from?->toDateString(),
            'dateTo' => $this->date_to?->toDateString(),
        ], fn ($value) => $value !== null);
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
