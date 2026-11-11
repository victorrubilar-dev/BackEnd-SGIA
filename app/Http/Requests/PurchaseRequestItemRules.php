<?php

namespace App\Http\Requests;

/**
 * Reglas compartidas de `items[{product_id, quantity}]` de la solicitud de compra.
 */
final class PurchaseRequestItemRules
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(bool $required): array
    {
        return [
            'items' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'items.required' => 'Agrega al menos un producto a la solicitud.',
            'items.min' => 'Agrega al menos un producto a la solicitud.',
            'items.*.product_id.required' => 'Cada ítem debe indicar su producto.',
            'items.*.product_id.distinct' => 'El producto está repetido en la solicitud.',
            'items.*.product_id.exists' => 'El producto del ítem no existe.',
            'items.*.quantity.required' => 'Cada ítem debe indicar su cantidad.',
            'items.*.quantity.min' => 'La cantidad de cada ítem debe ser al menos 1.',
        ];
    }
}
