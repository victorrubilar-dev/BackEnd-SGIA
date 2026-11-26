<?php

namespace App\Http\Requests;

use App\Models\Product;
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
            'oracle_category_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'string', 'in:'.implode(',', Product::OPERATIONAL_STATUSES)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'storeroom_id.integer' => 'El pañol indicado no es válido.',
            'storeroom_id.exists' => 'El pañol indicado no existe.',
            'oracle_category_id.integer' => 'La categoría indicada no es válida.',
            'estado.in' => 'El estado debe ser: '.implode(', ', Product::OPERATIONAL_STATUSES).'.',
            'estado.string' => 'El estado debe ser: '.implode(', ', Product::OPERATIONAL_STATUSES).'.',
        ];
    }
}
