<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9\sáéíóúÁÉÍÓÚñÑüÜ\-\.\,\/]+$/u',
            ],
            'description' => ['nullable', 'string'],
            'barcode' => ['nullable', 'string', 'max:50', 'unique:products,barcode'],
            'type' => ['sometimes', 'string', Rule::in(Product::TYPES)],
            'quantity' => ['required', 'integer', 'min:0'],
            'units' => ['sometimes', 'array', 'max:'.max(0, (int) $this->input('quantity'))],
            'units.*.serial_number' => ['nullable', 'string', 'max:100', 'distinct', 'unique:equipment_units,serial_number'],
            'stock_minimo' => ['sometimes', 'integer', 'min:0'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'cajon_id' => ['nullable', 'integer', 'exists:cajones,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'sala' => ['sometimes', 'nullable', 'string', 'max:50'],
            'cajon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'career_id' => [Rule::requiredIf(fn () => $this->needsGeneratedCode()), 'nullable', 'integer', Rule::exists('careers', 'id')->whereNotNull('code')],
            'maintenance_priority' => ['sometimes', 'string', Rule::in(Product::MAINTENANCE_PRIORITIES)],
            'requires_calibration' => ['sometimes', 'boolean'],
            'maintenance_frequency_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'reference_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'photo_url' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Insumo sin `barcode`, o equipo/maqueta con unidades por crear: el código se genera
     * con el secuenciador y exige una carrera con código.
     */
    private function needsGeneratedCode(): bool
    {
        $type = $this->input('type', Product::TYPE_EQUIPMENT);

        return ($type === Product::TYPE_SUPPLY && empty($this->input('barcode')))
            || ($type !== Product::TYPE_SUPPLY && (int) $this->input('quantity') > 0);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del producto es obligatorio.',
            'name.regex' => 'El nombre del producto no debe contener caracteres especiales.',
            'type.in' => 'El tipo de producto debe ser equipo, insumo o maqueta.',
            'category_id.required' => 'La categoría del producto es obligatoria.',
            'category_id.exists' => 'La categoría seleccionada no existe.',
            'career_id.required' => 'La carrera es obligatoria para generar el código de barras.',
            'units.max' => 'No puedes indicar más series que la cantidad de unidades.',
            'units.*.serial_number.unique' => 'Uno de los números de serie ya está registrado.',
            'units.*.serial_number.distinct' => 'Los números de serie no pueden repetirse.',
            'career_id.exists' => 'La carrera seleccionada no existe o no tiene código.',
            'maintenance_priority.in' => 'La prioridad de mantención debe ser A, B o C.',
            'quantity.required' => 'La cantidad de stock es obligatoria.',
            'quantity.integer' => 'La cantidad debe ser un número entero.',
            'quantity.min' => 'La cantidad de producto debe ser positiva o cero.',
            'supplier_id.exists' => 'El proveedor seleccionado no existe en el sistema.',
            'cajon_id.exists' => 'El cajón seleccionado no existe.',
            'location_id.exists' => 'La ubicación seleccionada no es válida.',
            'barcode.unique' => 'El código de barras ya está registrado para otro producto.',
            'stock_minimo.min' => 'El stock mínimo no puede ser negativo.',
        ];
    }
}
