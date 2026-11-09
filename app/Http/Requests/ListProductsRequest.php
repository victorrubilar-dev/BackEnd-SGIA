<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'storeroom_id' => ['nullable', 'integer', 'exists:storerooms,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'storeroom_id.integer' => 'El pañol indicado no es válido.',
            'storeroom_id.exists' => 'El pañol indicado no existe.',
        ];
    }
}
