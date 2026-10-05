<?php

namespace App\Http\Requests;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;

class StoreLoanRequest extends FormRequest
{
    /**
     * Acepta los alias en español usados por los clientes móviles:
     * sala -> room, asignatura -> subject, fecha -> loan_date.
     */
    public function prepareForValidation(): void
    {
        $aliases = [
            'room' => $this->input('sala'),
            'subject' => $this->input('asignatura'),
            'loan_date' => $this->input('fecha') ?? $this->input('fecha_prestamo'),
        ];

        $this->merge(array_filter($aliases, fn ($value) => $value !== null && $value !== ''));
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Loan::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'subject' => ['required', 'string', 'max:100'],
            'room' => ['required', 'string', 'max:100'],
            'loan_date' => ['required', 'date'],
            'time_block' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'La solicitud debe indicar al menos un insumo o producto.',
            'items.array' => 'El campo items debe ser una lista.',
            'items.min' => 'La solicitud debe incluir al menos un producto.',
            'items.*.product_id.required' => 'Cada ítem de la solicitud debe indicar el producto.',
            'items.*.product_id.exists' => 'El producto solicitado no existe en el inventario.',
            'items.*.quantity.required' => 'Cada ítem de la solicitud debe indicar la cantidad.',
            'items.*.quantity.min' => 'La cantidad solicitada debe ser al menos 1.',
            'subject.required' => 'La asignatura es obligatoria.',
            'subject.max' => 'La asignatura no puede superar los 100 caracteres.',
            'room.required' => 'La sala o taller es obligatorio.',
            'room.max' => 'La sala no puede superar los 100 caracteres.',
            'loan_date.required' => 'La fecha del préstamo es obligatoria.',
            'loan_date.date' => 'La fecha del préstamo debe ser una fecha válida.',
        ];
    }
}
