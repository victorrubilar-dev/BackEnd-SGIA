<?php

namespace App\Http\Requests;

use App\Models\IncidentReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIncidentReportStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateStatus', $this->route('incidentReport')) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(IncidentReport::STATUSES)],
        ];
    }
}
