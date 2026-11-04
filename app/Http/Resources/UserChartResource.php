<?php

namespace App\Http\Resources;

use App\Models\UserChart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserChart
 */
class UserChartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'chart_template_id' => $this->chart_template_id,
            'template' => new ChartTemplateResource($this->whenLoaded('template')),
            'custom_title' => $this->custom_title,
            'type' => $this->type,
            'position' => $this->position,
            'entity' => $this->entity,
            'metric' => $this->metric,
            'group_by' => $this->group_by,
            'period' => $this->period,
            'date_from' => $this->date_from?->toDateString(),
            'date_to' => $this->date_to?->toDateString(),
            'is_active' => $this->is_active,
            // Listo para enviar como `input` de `customMetric` en POST /api/metrics.
            'metric_input' => $this->metricInput(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
