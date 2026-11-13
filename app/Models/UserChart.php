<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gráfico del dashboard personalizado de un usuario (máximo 9 posiciones por plantilla; sin plantilla = panel principal).
 */
class UserChart extends Model
{
    use HasFactory;

    public const MAX_POSITION = 9;

    protected $fillable = [
        'user_id', 'chart_template_id', 'dashboard_template_id', 'custom_title', 'type', 'position',
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DashboardTemplate, $this> */
    public function dashboardTemplate(): BelongsTo
    {
        return $this->belongsTo(DashboardTemplate::class);
    }

    /** @return BelongsTo<ChartTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ChartTemplate::class, 'chart_template_id');
    }
}
