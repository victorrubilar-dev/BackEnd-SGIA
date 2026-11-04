<?php

namespace App\Http\Resources;

use App\Models\ChartTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChartTemplate
 */
class ChartTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'visualization_type' => $this->visualization_type,
            'chart_type' => $this->chart_type,
            'entity' => $this->entity,
            'metric' => $this->metric,
            'group_by' => $this->group_by,
            'period' => $this->period,
        ];
    }
}
