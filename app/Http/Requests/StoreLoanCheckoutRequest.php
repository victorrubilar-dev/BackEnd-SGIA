<?php

namespace App\Http\Requests;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;

class StoreLoanCheckoutRequest extends FormRequest
{
    /**
     * Acepta los alias en español usados por los clientes móviles:
     * sala -> room, asignatura -> subject, fecha -> loan_date,
     * solicitante -> borrower_name, credencial -> borrower_document.
     */
    public function prepareForValidation(): void
    {
        $aliases = [
            'room' => $this->input('sala'),
            'subject' => $this->input('asignatura'),
            'loan_date' => $this->input('fecha') ?? $this->input('fecha_prestamo'),
            'borrower_name' => $this->input('solicitante') ?? $this->input('person') ?? $this->input('borrower'),
            'borrower_document' => $this->input('credencial') ?? $this->input('document'),
        ];

        $this->merge(array_filter($aliases, fn ($value) => $value !== null && $value !== ''));
    }

    public function authorize(): bool
    {
        return $this->user()?->can('checkout', Loan::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'borrower_name' => ['required', 'string', 'max:255'],
            'borrower_document' => ['nullable', 'string', 'max:50'],
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
            'borrower_name.required' => 'El nombre del profesor o estudiante que recibe el material es obligatorio.',
            'borrower_document.max' => 'La credencial no puede superar los 50 caracteres.',
            'items.required' => 'El préstamo debe incluir al menos un producto entregado.',
            'items.array' => 'El campo items debe ser una lista.',
            'items.min' => 'El préstamo debe incluir al menos un producto entregado.',
            'items.*.product_id.required' => 'Cada ítem debe indicar el producto entregado.',
            'items.*.product_id.exists' => 'El producto entregado no existe en el inventario.',
            'items.*.quantity.required' => 'Cada ítem debe indicar la cantidad entregada.',
            'items.*.quantity.min' => 'La cantidad entregada debe ser al menos 1.',
            'subject.required' => 'La asignatura es obligatoria.',
            'room.required' => 'La sala o taller es obligatorio.',
            'loan_date.required' => 'La fecha del préstamo es obligatoria.',
            'loan_date.date' => 'La fecha del préstamo debe ser una fecha válida.',
        ];
    }
}
