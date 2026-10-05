<?php

namespace App\Http\Requests;

use App\Models\Cajon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCajonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && in_array($this->user()->role, ['AD-01', 'DIR-01', 'PAN-01'], true);
    }

    public function rules(): array
    {
        $cajon = $this->route('cajon');
        $cajonId = $cajon instanceof Cajon ? $cajon->id : $cajon;
        $locationId = $this->input('location_id', $cajon instanceof Cajon ? $cajon->location_id : null);

        return [
            'location_id' => ['sometimes', 'required', 'integer', 'exists:locations,id'],
            'codigo' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('cajones', 'codigo')
                    ->where(fn ($query) => $query->where('location_id', $locationId))
                    ->ignore($cajonId),
            ],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }
}
