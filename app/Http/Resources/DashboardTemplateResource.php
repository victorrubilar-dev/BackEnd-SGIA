<?php

namespace App\Http\Resources;

use App\Models\DashboardTemplate;
use App\Models\UserChart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DashboardTemplate
 */
class DashboardTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'charts_count' => $this->whenCounted('charts'),
            'max_charts' => UserChart::MAX_POSITION,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
