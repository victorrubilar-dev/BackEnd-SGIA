<?php

namespace App\Http\Resources;

use App\Models\ReportSubscription;
use App\Support\ReportSubscriptionPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una suscripción de reporte por correo (contrato en docs/suscripcion-reportes.md).
 *
 * @mixin ReportSubscription
 */
class ReportSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_id' => $this->report_id,
            'title' => $this->title,
            'path' => $this->path,
            'params' => (object) $this->params,
            'filters' => (object) $this->filters,
            'frequency' => $this->frequency,
            'is_active' => $this->is_active,
            'paused_reason' => $this->paused_reason,
            'next_send_at' => $this->is_active ? ReportSubscriptionPeriod::nextSendAt($this->frequency)->toIso8601String() : null,
            'last_sent_at' => $this->last_sent_at?->toIso8601String(),
            'last_status' => $this->last_status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
