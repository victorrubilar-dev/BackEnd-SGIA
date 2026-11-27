<?php

namespace App\Http\Requests;

use App\Models\ReportSubscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** `PATCH /api/me/report-subscriptions/{id}`: frecuencia y pausar/reanudar, solo de las propias. */
class UpdateReportSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Antes de validar: una suscripción ajena responde `404`.
        Gate::authorize('update', $this->route('reportSubscription'));

        return true;
    }

    public function rules(): array
    {
        return [
            'frequency' => ['sometimes', 'required', Rule::in(ReportSubscription::FREQUENCIES)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'frequency.required' => 'Elige la frecuencia.',
            'frequency.in' => 'La frecuencia no es válida.',
            'is_active.required' => 'Indica si la suscripción está activa.',
            'is_active.boolean' => 'Indica si la suscripción está activa.',
        ];
    }
}
