<?php

namespace App\Http\Requests;

use App\Models\PurchaseRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequestQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('purchaseRequest')) ?? false;
    }

    public function rules(): array
    {
        /** @var PurchaseRequest $request */
        $request = $this->route('purchaseRequest');
        $itemCount = $request->items()->count();

        return [
            'supplier_id' => ['required', 'integer', Rule::exists('purchase_request_suppliers', 'supplier_id')->where('purchase_request_id', $request->id)],
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp,doc,docx,xls,xlsx', 'max:10240'],
            'total' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'received_at' => ['nullable', 'date'],
            // Precio de cada ítem de la solicitud (lo usa la OC): uno por ítem, sin faltantes.
            'items' => ['required', 'array', "size:{$itemCount}"],
            'items.*.purchase_request_item_id' => ['required', 'integer', 'distinct', Rule::exists('purchase_request_items', 'id')->where('purchase_request_id', $request->id)],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Indica el proveedor de la cotización.',
            'supplier_id.exists' => 'El proveedor no fue contactado en esta solicitud.',
            'file.required' => 'El archivo de la cotización es obligatorio.',
            'file.mimes' => 'El archivo debe ser PDF, imagen (PNG, JPG, WEBP), Word o Excel.',
            'file.max' => 'El archivo no puede exceder los 10MB.',
            'total.required' => 'Indica el total de la cotización.',
            'total.gt' => 'El total de la cotización debe ser mayor que 0.',
            'items.required' => 'Indica el precio unitario de cada producto de la solicitud.',
            'items.size' => 'Indica el precio unitario de cada producto de la solicitud (uno por producto).',
            'items.*.purchase_request_item_id.required' => 'Cada precio debe indicar el ítem de la solicitud.',
            'items.*.purchase_request_item_id.distinct' => 'El ítem está repetido.',
            'items.*.purchase_request_item_id.exists' => 'El ítem no pertenece a esta solicitud.',
            'items.*.unit_price.required' => 'Indica el precio unitario.',
            'items.*.unit_price.min' => 'El precio unitario no puede ser negativo.',
        ];
    }
}
